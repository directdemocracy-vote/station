<?php

function stripped_key($public_key) {
  $stripped = "";
  $header = strlen("-----BEGIN RSA PUBLIC KEY-----\n");
  $footer = strlen("-----END RSA PUBLIC KEY-----");
  $l = strlen($public_key) - $footer;
  for($i = $header; $i < $l; $i += 65)
    $stripped .= substr($public_key, $i, 64);
  $stripped = substr($stripped, 0, -2 - $footer);
  return $stripped;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$public_key_file = fopen("../id_rsa.pub", "r") or die("{\"error\":\"unable to open public key file\"}");
$k = fread($public_key_file, filesize("../id_rsa.pub"));
fclose($public_key_file);
$key = stripped_key($k);
die("{\"key\":\"$key\"}");
?>
