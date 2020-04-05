<?php
require_once '../php/database.php';

function error($message) {
  die("{\"error\":\"$message\"}");
}

function public_key($key) {
  $public_key = "-----BEGIN PUBLIC KEY-----\n";
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $public_key .= substr($key, $i, 64) . "\n";
  $public_key.= "-----END PUBLIC KEY-----";
  return $public_key;
}

function stripped_key($public_key) {
  $stripped = str_replace("-----BEGIN PUBLIC KEY-----", "", $public_key);
  $stripped = str_replace("-----END PUBLIC KEY-----", "", $stripped);
  $stripped = str_replace("\r\n", '', $stripped);
  $stripped = str_replace("\n", '', $stripped);
  return $stripped;
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");

$publication = json_decode(file_get_contents("php://input"));
if (!$publication)
  error("Unable to parse JSON post");
if (!isset($publication->schema))
  error("Unable to read schema field");

$url = 'https://directdemocracy.vote/json-schema/';
if (substr($publication->schema, 0, 41) !== $url)
  error("Wrong schema URL: " . substr($publication->schema, 0, 41));

if (substr($publication->schema, -19) != '/ballot.schema.json')
  error("Wrong schema object" . substr($publication->schema, -19));

$directdemocracy_version = substr($publication->schema, 41, -19);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported version: $directdemocracy_version");

$now = floatval(microtime(true) * 1000);  # milliseconds
if ($publication->published > $now + 60000)  # allowing a 1 minute error
  error("Publication date in the future: $publication->published > $now");
if ($publication->expires < $now - 60000)  # allowing a 1 minute error
  error("Expiration date in the past: $publication->expires < $now");

$station_key = file_get_contents('../id_rsa.pub');
if ($publication->station->key !== stripped_key($station_key))
  error("Wrong station key");

$signature = $publication->signature;
$publication->signature = '';
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), public_key($publication->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong citizen signature");
$publication->signature = $signature;

# publish the fact that citizen has voted
# sign the ballot
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$private_key = openssl_get_privatekey("file://../id_rsa");
if ($private_key == FALSE)
  error("Failed to read private key.");
$signature = '';
$success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
openssl_free_key($private_key);
if ($success === FALSE)
  error("Failed to sign ballot.");
$publication->station->signature = base64_encode($signature);
# publish the ballot
$data = json_encode($publication, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$publisher = 'https://publisher.directdemocracy.vote';
$options = array('http' => array('method' => 'POST',
                                 'content' => $data,
                                 'header' => "Content-Type: application/json\r\n" .
                                             "Accept: application/json\r\n"));
$response = file_get_contents("$publisher/publish.php", false, stream_context_create($options));

# clear the link between the citizen and the ballot
$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');
$query = "UPDATE ballot SET `key`='', signature='' " .
         "WHERE citizen = '$publication->key' AND referendum = '$publication->referendum' " .
         "AND `schema` = '$publication->schema'";
$mysqli->query($query) or error($mysqli->error);
if ($mysqli->affected_rows !== 1)
  die("Error: affected_rows = $mysqli->affected_rows");
$mysqli->close();

die($response);
?>
