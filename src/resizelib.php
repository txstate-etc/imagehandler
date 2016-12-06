<?php
date_default_timezone_set('GMT');
$GLOBALS['auth_map'] = json_decode($_ENV['IMAGEHANDLERAUTHJSON'], TRUE);
$GLOBALS['stats'] = array();

function get_mime_type($imagedata) {
  $info = new finfo(FILEINFO_MIME);
  return $info->buffer($imagedata);
}

function check_magic_bytes($imagedata) {
  $mimetype = get_mime_type($imagedata);
  $GLOBALS['stats']['image_type'] = $mimetype;
  return (0===strpos($mimetype, 'image/'));
}

function crop_image($image, $width, $height, $x, $y) {
	if ($image->getImageWidth() == $width && $image->getImageHeight() == $height) return $image;
	if ($image->count() > 1 && !$image->coalesced) {
		$image = $image->coalesceImages();
		$image->coalesced = true;
	}
	foreach ($image as $frame) {
		$image->cropImage($width, $height, $x, $y);
		$image->setImagePage($width, $height, 0, 0);
	}
	return $image;
}

function resize_image($image, $width, $height) {
	if ($image->count() > 1 && $image->getImageColors() < 200) return $image;
	if ($image->count() > 1 && $width * $height > ($image->getImageWidth() * $image->getImageHeight() * 0.8)) return $image;
	if ($image->getImageWidth() == $width && $image->getImageHeight() == $height) return $image;
	if ($image->count() > 1 && !$image->coalesced) {
		$image = $image->coalesceImages();
		$image->coalesced = true;
	}
	foreach ($image as $frame) {
		$image->resizeImage($width, $height, imagick::FILTER_MITCHELL, 1);
	}
	return $image;
}

function benchmark($start) {
  return round((microtime(TRUE) - $start)*1000);
}

function get_raw_image($requesturl, $lastmod='') {
  $path = get_raw_cache_path($requesturl);
  $etagpath = $path.'/etag';
  if (file_exists($etagpath)) $fileetag = file_get_contents($etagpath);

  $basepath = "/imagehandler/scaler";
  $location = substr($requesturl, strlen($basepath));
  $urlinfo = parse_url("http:/".$location);
  $info = $GLOBALS['auth_map'][$urlinfo['host']];
  $imglocation = ($info[2] == 'SSL' ? 'https:/' : 'http:/').$location;

  $headers = '';
  if ($lastmod) $headers .= "If-Modified-Since: ".$lastmod." GMT\r\n";
  if ($info[0]) $headers .= "Authorization: Basic " . base64_encode($info[0].':'.$info[1])."\r\n";

  $context = array(
  	'http' => array(
      'method' => "GET",
      'header' => $headers
    )
  );
  $imgdata = file_get_contents($imglocation, false, stream_context_create($context));
  if ($lastmod) {
    if (preg_match('/304/', $http_response_header[0])) return false;
    if (md5($imgdata) == $fileetag) return false;
  }

  if (!check_magic_bytes($imgdata)) {
    // extra layer of security against exploits
    // imagemagick exploit found in the wild 2016-05-06
    http_response_code(400);
    exit;
  }

  $GLOBALS['stats']['filesize_input'] = strlen($imgdata);
  if (!file_exists($path)) mkdir($path, 0755, true);
  file_put_contents($etagpath, md5($imgdata));

  $image = new Imagick();
  $image->readImageBlob($imgdata);
  return $image;
}

function get_raw_cache_path($requesturl) {
  list ($requestwithoutquery) = preg_split('/\?/', $requesturl, 2);
  return get_cache_path($requestwithoutquery);
}

function get_cache_path($requesturl) {
  $info = explode('/',$requesturl);
  $signature = implode('/', array_slice($info, 4));
  $hash = md5($signature);
  $cachepath = substr($hash,0,2).'/'.substr($hash,2,2).'/'.substr($hash,4,2).'/'.substr($hash,6);
  $fullcachepath = '/var/cache/resize/'.$cachepath;
  return $fullcachepath;
}

function store_image($path, $blob, $etag) {
  $filepath = $path.'/data';
  $etagpath = $path.'/etag';
  if (!file_exists($path)) mkdir($path, 0755, true);
  $tmppath = $path.'/tmp'.substr( md5(rand()), 0, 7);
  file_put_contents($tmppath, $blob);
  rename($tmppath, $filepath);
  file_put_contents($etagpath, $etag);
}

function store_resized_image($requesturl, $blob, $etag) {
  return store_image(get_cache_path($requesturl), $blob, $etag);
}

function print_cache_image_or_return_original($requesturl, $etag, $lastmodified) {
  $path = get_cache_path($requesturl);
  $filepath = $path.'/data';
  $etagpath = $path.'/etag';

  if (file_exists($filepath)) {
    $filelmtime =  filemtime($filepath);
    $filelm = gmdate("D, d M Y H:i:s", $filelmtime);
  }

  $start = microtime(TRUE);
  $image = get_raw_image($requesturl, $filelm);
  $GLOBALS['stats']['time_retrieve'] = benchmark($start);

  if ($image) return $image;

  if (!file_exists($filepath) || !file_exists($etagpath)) return $image;
  $GLOBALS['stats']['cache_hit'] = TRUE;

  $fileetag = trim(file_get_contents($etagpath));

  if ((!$etag && !$lastmodified) || ($etag && trim($etag) != $fileetag) || ($lastmodified && strtotime($lastmodified) < $filelmtime)) {
    $start = microtime(TRUE);
    $cachedimage = file_get_contents($filepath);
    $GLOBALS['stats']['time_cacheread'] = benchmark($start);
    print_blob($cachedimage, $filelm, $fileetag);
  } else {
    print_304($filelm, $fileetag);
  }
  return false;
}

function print_blob($blob, $lastmod, $etag) {
  $start = microtime(TRUE);
  header('Content-Type: '.get_mime_type($blob));
  header("Last-Modified: ".$lastmod." GMT");
  header("Etag: $etag");
  echo $blob;
  flush();
  $GLOBALS['stats']['filesize_output'] = strlen($blob);
  $GLOBALS['stats']['time_stream'] = benchmark($start);
}

function print_304($lastmod, $etag) {
  $GLOBALS['stats']['not_modified'] = TRUE;
  $start = microtime(TRUE);
  header('HTTP/1.1 304 Not Modified');
  header("Last-Modified: ".$lastmod." GMT");
  header("Etag: $etag");
  flush();
  $GLOBALS['stats']['time_stream'] = benchmark($start);
}

function log_event_statistics() {
  $stats = $GLOBALS['stats'];
  $stats['request_uri'] = $_SERVER['REQUEST_URI'];
  $stats['referer'] = $_SERVER['HTTP_REFERER'];
  $stats['hostname'] = gethostname();
  $stats['environment'] = $_ENV['IMAGEHANDLERSTAGE'] ?: 'development';
}
?>
