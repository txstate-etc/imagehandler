<?php
error_reporting(E_WARNING | E_PARSE);

require_once('resizelib.php');

$image = print_cache_image_or_return_original($_SERVER['REQUEST_URI'], $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
if (!$image) exit;

$boxw = $_GET['width'] ?: 0;
$boxh = $_GET['height'] ?: 0;
$clip = $_GET['mode'] == 'clip';
$quality = $_GET['quality'] ?: 65;

$w = $image->getImageWidth();
$h = $image->getImageHeight();
$animated = $image->getImageIterations() > 0;
if ($animated) $image->coalesceImages();

$croptop = $_GET['croptop'] ?: 0.0;
$cropleft = $_GET['cropleft'] ?: 0.0;
$cropbottom = $_GET['cropbottom'] ?: 1.0;
$cropright = $_GET['cropright'] ?: 1.0;
if ($croptop > 0.0 || $cropbottom < 1.0 || $cropleft > 0.0 || $cropright < 1.0) {
  $tmpw = round(($cropright-$cropleft)*$w);
  $tmph = round(($cropbottom-$croptop)*$h);
  $image->cropImage($tmpw, $tmph, $cropleft*$w, $croptop*$h);
  $image->setImagePage($tmpw, $tmph, 0, 0);
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
    $image->cropImage($cropw, $croph, ($w-$cropw)/2.0, ($h-$croph)/2.0);
    $image->setImagePage($cropw, $croph, 0, 0);
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

$image->resizeImage($neww, $newh, imagick::FILTER_MITCHELL, 1);
if ($animated) $image->deconstructImages();

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

$blob = $image->getImagesBlob();

print_blob($blob, gmdate("D, d M Y H:i:s"), md5($blob));

store_resized_image($_SERVER['REQUEST_URI'], $blob, $etag);
?>
