<?php
date_default_timezone_set('GMT');

function get_mime_type($imagedata) {
  $info = new finfo(FILEINFO_MIME);
  return $info->buffer($imagedata);
}

function check_magic_bytes($imagedata) {
  return (0===strpos(get_mime_type($imagedata), 'image/'));
}

function get_raw_image($requesturl, $lastmod='') {
  $path = get_raw_cache_path($requesturl);
  $etagpath = $path.'/etag';
  if (file_exists($etagpath)) $fileetag = file_get_contents($etagpath);

  $basepath = $_SERVER['SCRIPT_NAME']."/imagehandler/scaler";
  $imglocation = "http:/".substr($requesturl, strlen($basepath));
  $context = array();
  if ($lastmod) $context = array(
    'http' => array(
      'method' => "GET",
      'header' => "If-Modified-Since: ".$lastmod." GMT\r\n"
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

  $filelmtime =  filemtime($filepath);
  $filelm = gmdate("D, d M Y H:i:s", $filelmtime);
  if (!file_exists($filepath) || !file_exists($etagpath)) return get_raw_image($requesturl);

  $image = get_raw_image($requesturl, $filelm);
  if ($image) return $image;

  $fileetag = trim(file_get_contents($etagpath));

  if ((!$etag && !$lastmodified) || ($etag && trim($etag) != $fileetag) || ($lastmodified && strtotime($lastmodified) < $filelmtime)) {
    print_blob(file_get_contents($filepath), $filelm, $fileetag);
  } else {
    print_304($filelm, $fileetag);
  }
  return false;
}

function print_blob($blob, $lastmod, $etag) {
  header('Content-Type: '.get_mime_type($blob));
  header("Last-Modified: ".$lastmod." GMT");
  header("Etag: $etag");
  echo $blob;
}

function print_304($lastmod, $etag) {
  header('HTTP/1.1 304 Not Modified');
  header("Last-Modified: ".$lastmod." GMT");
  header("Etag: $etag");
}
?>
