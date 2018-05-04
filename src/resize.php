<?php
error_reporting(E_WARNING | E_PARSE | E_ERROR);
ignore_user_abort(TRUE);
$totalstart = microtime(TRUE);
require_once('resizelib.php');

$croptop = $_GET['croptop'] ?: 0.0;
$cropleft = $_GET['cropleft'] ?: 0.0;
$cropbottom = $_GET['cropbottom'] ?: 1.0;
$cropright = $_GET['cropright'] ?: 1.0;
$is_cropped = $croptop > 0 || $cropleft > 0 || $cropbottom < 1 || $cropright < 1;
$GLOBALS['stats']['cropped'] = $is_cropped;

$image = print_cache_image_or_return_original($_SERVER['REQUEST_URI'], $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
if ($image) {
  $start = microtime(TRUE);
  $boxw = $_GET['width'] ?: 0;
  $boxh = $_GET['height'] ?: 0;
  $clip = $_GET['mode'] == 'clip';
  $quality = $_GET['quality'] ?: 65;

  $w = $image->getImageWidth();
  $h = $image->getImageHeight();

  $GLOBALS['stats']['pixelcount_input'] = $w*$h;
  $GLOBALS['stats']['resolution_input'] = "$w x $h";
  $GLOBALS['stats']['animated'] = $image->getNumberImages() > 1;
  $GLOBALS['stats']['colors'] = $image->getImageColors();

  $GLOBALS['stats']['format_input'] = $image->getImageFormat();
  // determine whether we should change format to jpg
  if (in_array(strtolower($image->getImageFormat()), array('png','png24','png8','bigtiff','tif','tiff','bmp', 'bmp2', 'bmp3'))) {
    if ($image->getImageChannelExtrema(Gmagick::CHANNEL_OPACITY)['maxima'] > 0)
      $image->setImageFormat("PNG");
    elseif ($image->getImageColors() < 255)
      $image->setImageFormat("PNG8");
    elseif ($image->getImageColors() < 10000)
      $image->setImageFormat("PNG");
    else {
      $image->setImageFormat("JPG");
      $quality = 90;
    }
  }
  $GLOBALS['stats']['format_output'] = $image->getImageFormat();

  if ($is_cropped) {
    $tmpw = round(($cropright-$cropleft)*$w);
    $tmph = round(($cropbottom-$croptop)*$h);
    $image = crop_image($image, $tmpw, $tmph, $cropleft*$w, $croptop*$h);
    $w = $tmpw;
    $h = $tmph;
  }

  $ar = (1.0*$w)/(1.0*$h);
  $boxar = $boxh > 0 ? (1.0*$boxw)/(1.0*$boxh) : 0;
  if ($clip && $boxw > 0 && $boxh > 0) {
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
  $GLOBALS['stats']['resolution_output'] = "$neww x $newh";
  $image = resize_image($image, $neww, $newh);

  // not sure what these do, found some advice to set them this way
  $image->setimageinterlacescheme(Gmagick::INTERLACE_NO);

  // get rid of excess metadata
  $image->stripimage();

  // set PNG compression level
  if (strtolower($image->getimageformat()) == "png") $quality = 95;
  $image->setCompressionQuality($quality);
  $GLOBALS['stats']['quality'] = $quality;

  if ($image->getNumberImages() > 1) {
    if (!file_exists('/var/cache/resize/gifsicle/')) mkdir('/var/cache/resize/gifsicle/', 0755, true);
    $tmppath = '/var/cache/resize/gifsicle/tmp'.substr( md5(rand()), 0, 7);
    $image->writeimage($tmppath, true);
    $image->clear();
    system('gifsicle --optimize=2 --no-extensions '.$tmppath.' -o '.$tmppath.'-o');
    $image = new Gmagick($tmppath.'-o');
    unlink($tmppath);
    unlink($tmppath.'-o');
  }

  $image->setimageindex(0);
  $blob = $image->getImagesBlob();
  store_resized_image($_SERVER['REQUEST_URI'], $blob, $etag);

  $GLOBALS['stats']['time_process'] = benchmark($start);

  print_blob($blob, gmdate("D, d M Y H:i:s"), md5($blob));
}
$GLOBALS['stats']['time_total'] = benchmark($totalstart);
log_event_statistics();
?>
