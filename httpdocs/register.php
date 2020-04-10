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
$publications = json_decode(file_get_contents("php://input"));

if (!$publications)
  error("Unable to parse JSON post");
if (!isset($publications->ballot))
  error("Missing ballot");
if (!isset($publications->registration))
  error("Missing registration");

$ballot = $publications->ballot;
$registration = $publications->registration;

if (!isset($ballot->schema))
  error("Unable to read ballot schema field");
if (!isset($registration->schema))
  error("Unable to read registration schema field");

$url = 'https://directdemocracy.vote/json-schema/';
if (substr($ballot->schema, 0, 41) !== $url)
  error("Wrong ballot schema URL: " . substr($ballot->schema, 0, 41));
if (substr($registration->schema, 0, 41) !== $url)
  error("Wrong registration schema URL: " . substr($registration->schema, 0, 41));

if (substr($ballot->schema, -19) != '/ballot.schema.json')
  error("Wrong ballot schema: " . substr($ballot->schema, -19));
if (substr($registration->schema, -25) != '/registration.schema.json')
  error("Wrong registration schema: " . substr($registration->schema, -25));

$directdemocracy_version = substr($ballot->schema, 41, -19);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported ballot version: $directdemocracy_version");
$directdemocracy_version = substr($registration->schema, 41, -25);
if ($directdemocracy_version !== '0.0.1')
  error("Unsupported registration version: $directdemocracy_version");

$now = floatval(microtime(true) * 1000);  # milliseconds
if ($ballot->expires < $now - 60000)  # allowing a 1 minute error
  error("Ballot expiration date in the past: $ballot->expires < $now");
# FIXME: ballot published date should match referendum deadline
# and expires date should match the referendum deadline + 1 year.
if ($registration->published > $now + 60000)  # allowing a 1 minute error
  error("Registration publication date in the future: $registration->published > $now");
if ($registration->expires < $now - 60000)  # allowing a 1 minute error
  error("Registration expiration date in the past: $registration->expires < $now");

if ($ballot->referendum != $registration->referendum)
  error("Mismatching referendum in ballot and registration");
$station_key = stripped_key(file_get_contents('../id_rsa.pub'));
if ($ballot->station->key !== $station_key)
  error("Wrong station key in ballot");
if ($registration->station->key !== $station_key)
  error("Wrong station key in registration");

# verify ballot signature
$signature = $ballot->signature;
$ballot->signature = '';
$data = json_encode($ballot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), public_key($ballot->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong ballot signature");
$ballot->signature = $signature;

# verify registration signature by citizen
$signature = $registration->signature;
$registration->signature = '';
$data = json_encode($registration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), public_key($registration->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong signature in registration");
$registration->signature = $signature;

# get trustee url from publisher
# FIXME: this should not be needed if checking from publications only
$publisher = 'https://publisher.directdemocracy.vote';
$trustee = file_get_contents("$publisher/trustee_url.php?referendum=" . urlencode($ballot->referendum));

if (substr($trustee, 0, 8) !== 'https://')
  die("Cannot get referendum trustee: $trustee");

# check if citizen is allowed by trustee to vote to this referendum
# FIXME: should check from publications only
$allowed = file_get_contents("$trustee/can_vote.php?referendum=" . urlencode($registration->referendum) .
                             "&citizen=" . urlencode($registration->key));
if ($allowed !== 'yes')
  die("Citizen is not allowed to vote to this referendum by trustee: $allowed");

# sign the ballot
$data = json_encode($ballot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$private_key = openssl_get_privatekey("file://../id_rsa");
if ($private_key == FALSE)
  error("Failed to read private key.");
$signature = '';
$success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
if ($success === FALSE)
  error("Failed to sign ballot.");
$ballot->station->signature = base64_encode($signature);

# publish the ballot FIXME: it should be published later
$ballot_data = json_encode($ballot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$options = array('http' => array('method' => 'POST',
                                 'content' => $ballot_data,
                                 'header' => "Content-Type: application/json\r\n" .
                                             "Accept: application/json\r\n"));
$response = file_get_contents("$publisher/publish.php", false, stream_context_create($options));
$json = json_decode($response);
if (isset($json->error))
  error($json->error);

# sign the registration and publish it now
$data = json_encode($registration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$signature = '';
$success = openssl_sign($data, $signature, $private_key, OPENSSL_ALGO_SHA256);
openssl_free_key($private_key);
if ($success === FALSE)
  error("Failed to sign registration.");
$registration->station->signature = base64_encode($signature);
# publish the registration
$registration_data = json_encode($registration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$options = array('http' => array('method' => 'POST',
                                 'content' => $registration_data,
                                 'header' => "Content-Type: application/json\r\n" .
                                             "Accept: application/json\r\n"));
$response = file_get_contents("$publisher/publish.php", false, stream_context_create($options));
$json = json_decode($response);
if (isset($json->error))
  error($json->error);

# save ballot and registration information in database
$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');
$query = "INSERT INTO ballot(`schema`, `key`, signature, published, expires, referendum, citizenKey, citizenSignature) " .
         "VALUES('$ballot->schema', '$ballot->key', '$ballot->signature', " .
         "$ballot->published, $ballot->expires, '$ballot->referendum', '$registration->key', '$registration->signature')";
$mysqli->query($query) or error($mysqli->error);
$mysqli->close();

# send back signed ballot and signed registration to citizen
die("{\"ballot\":$ballot_data,\"registration\":$registration_data}");
?>
