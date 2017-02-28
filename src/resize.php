<?php
error_reporting(E_WARNING | E_PARSE);
$totalstart = microtime(TRUE);
require_once('resizelib.php');
register_shutdown_function('log_event_statistics');

$croptop = $_GET['croptop'] ?: 0.0;
$cropleft = $_GET['cropleft'] ?: 0.0;
$cropbottom = $_GET['cropbottom'] ?: 1.0;
$cropright = $_GET['cropright'] ?: 1.0;
$is_cropped = $croptop > 0 || $cropleft > 0 || $cropbottom < 1 || $cropright < 1;
$GLOBALS['stats']['cropped'] = $is_cropped;

$image = print_cache_image_or_return_original($_SERVER['REQUEST_URI'], $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
if ($image) {
  $image->setResourceLimit(imagick::RESOURCETYPE_THREAD, 1);
  $start = microtime(TRUE);
  $boxw = $_GET['width'] ?: 0;
  $boxh = $_GET['height'] ?: 0;
  $clip = $_GET['mode'] == 'clip';
  $quality = $_GET['quality'] ?: 65;

  $w = $image->getImageWidth();
  $h = $image->getImageHeight();
  $GLOBALS['stats']['pixelcount_input'] = $neww*$newh;
  $GLOBALS['stats']['animated'] = $image->count() > 1;

  if ($is_cropped) {
    $tmpw = round(($cropright-$cropleft)*$w);
    $tmph = round(($cropbottom-$croptop)*$h);
    $image = crop_image($image, $tmpw, $tmph, $cropleft*$w, $croptop*$h);
    $w = $tmpw;
    $h = $tmph;
  }

  $ar = (1.0*$w)/(1.0*$h);
  $boxar = $boxh > 0 ? (1.0*$boxw)/(1.0*$boxh) : 0;
  if ($boxar == 0) {
    $neww = $w;
    $newh = $h;
  } elseif ($clip && $boxw > 0 && $boxh > 0) {
    if ($ar > $boxar) { // image is too wide
      $croph = $h;
      $cropw = round($croph*$boxar);
    } else { // image is too tall
      $cropw = $w;
      $croph = round($cropw/$boxar);
    }
    if ($cropw != $w || $croph != $h) {
      $image = crop_image($image, $cropw, $croph, ($w-$cropw)/2.0, ($h-$croph)/2.0);
    }
    $neww = $boxw;
    $newh = $boxh;
  } else {
    if ($ar > $boxar && $boxw > 0) { // image is too wide
      $neww = $boxw;
      $newh = $neww/$ar;
    } else { // image is too tall
      $newh = $boxh;
      $neww = $newh*$ar;
    }
  }
  $GLOBALS['stats']['pixelcount_output'] = $neww*$newh;

  $image = resize_image($image, $neww, $newh);
  if ($image->coalesced) $image = $image->deconstructImages();

  /* OPTIMIZE */

  $image->setOption('jpeg:fancy-upsampling', 'off');

  // not sure what these do, found some advice to set them this way
  $image->setInterlaceScheme(imagick::INTERLACE_NO);
  $image->setColorspace(imagick::COLORSPACE_SRGB);

  // get rid of excess metadata
  $image->stripImage();
  $image->deleteImageProperty('comment');
  $image->deleteImageProperty('Thumb::URI');
  $image->deleteImageProperty('Thumb::MTime');
  $image->deleteImageProperty('Thumb::Size');
  $image->deleteImageProperty('Thumb::Mimetype');
  $image->deleteImageProperty('software');
  $image->deleteImageProperty('Thumb::Image::Width');
  $image->deleteImageProperty('Thumb::Image::Height');
  $image->deleteImageProperty('Thumb::Document::Pages');

  // set JPEG compression quality
  $image->setImageCompressionQuality($quality);

  // set PNG compression level
  $image->setOption('png:compression-filter', '5');
  $image->setOption('png:compression-level', '9');
  $image->setOption('png:compression-strategy', '1');
  $image->setOption('png:exclude-chunk', 'all');

  if ($image->count() > 1) {
    if (!file_exists('/var/cache/resize/gifsicle/')) mkdir('/var/cache/resize/gifsicle/', 0755, true);
    $image->setResourceLimit(imagick::RESOURCETYPE_MEMORY, 80000000);
    $image->setResourceLimit(imagick::RESOURCETYPE_MAP, 80000000);
    $tmppath = '/var/cache/resize/gifsicle/tmp'.substr( md5(rand()), 0, 7);
    $image->writeImages($tmppath, true);
    $image->clear();
    system('gifsicle --optimize=2 --no-extensions '.$tmppath.' -o '.$tmppath.'-o');
    $image = new Imagick($tmppath.'-o');
    unlink($tmppath);
    unlink($tmppath.'-o');
  }

  $blob = $image->getImagesBlob();
  register_shutdown_function('store_resized_image', $_SERVER['REQUEST_URI'], $blob, $etag);

  $GLOBALS['stats']['time_process'] = benchmark($start);

  print_blob($blob, gmdate("D, d M Y H:i:s"), md5($blob));
}
$GLOBALS['stats']['time_total'] = benchmark($totalstart);
?>
