<?php
require_once '../../php/database.php';

function error($message) {
  die("{\"error\":\"$message\"}");
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$registration = json_decode(file_get_contents("php://input"));

function stripped_key($key, $type) {
  $stripped = str_replace("-----BEGIN $type KEY-----", "", $key);
  $stripped = str_replace("-----END $type KEY-----", "", $stripped);
  $stripped = str_replace("\r\n", '', $stripped);
  $stripped = str_replace("\n", '', $stripped);
  return $stripped;
}

function unstripped_key($key, $type) {
  $unstripped_key = "-----BEGIN $type KEY-----\n";
  $l = strlen($key);
  for($i = 0; $i < $l; $i += 64)
    $unstripped_key .= substr($key, $i, 64) . "\n";
  $unstripped_key.= "-----END $type KEY-----";
  return $unstripped_key;
}

function blindSign($message, $privateKey) {
  return 'blind signature';  // FIXME: implement blind signature for $message using $privateKey
}

if (!$registration)
  error("Unable to parse JSON post");
if (!isset($registration->schema))
  error("Unable to read registration schema field");
if ($registration->schema != "https://directdemocracy.vote/json-schema/2/registration.schema.json")
  error("Wrong registration schema");
if (!isset($registration->signature))
  error("No registration signature");
$signature = $registration->signature;
$registration->signature = '';
$data = json_encode($registration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), unstripped_key($registration->key, 'PUBLIC'), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong registration signature");
# check if the citizen is allowed to vote
$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');
$blindKey = $mysqli->escape_string($registration->blindKey);
$query = "SELECT referendum, publicKey, privateKey FROM participation WHERE publicKey='$blindKey'";
$result = $mysqli->query($query) or error($mysqli->error);
$participation = $result->fetch_assoc();
if (!$participation)
  error("Participation not found");
$result->free();
$referendum = $participation['referendum'];
$citizen = $registration->key;
$data = "{\"referendum\":\"$referendum\",\"citizen\":\"$citizen\"}";
$opts = array('http' => array('method' => 'POST', 'header' => 'Content-type: application/json', 'content' => $data));
$context = stream_context_create($opts);
$answer = file_get_contents('https://notary.directdemocracy.vote/api/can_vote.php', false, $context);
if ($answer !== 'Yes')
  error("Not allowed to vote: $answer");
# create ballot with blind signature
$ballot = array();
$ballot['schema'] = 'https://directdemocracy.vote/json-schema/2/ballot.schema.json';
$ballot['key'] = stripped_key(file_get_contents('../../id_rsa.pub'), 'PUBLIC');
$ballot['signature'] = '';
$ballot['published'] = $published;
$ballot['encryptedVote'] = $participation['encryptedVote'];
$ballot['blindKey'] = $blindKey;
$ballot['blindSignature'] = blind_sign($participation['encryptedVoted'], $participation['privateKey']);
$data = json_encode($ballot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$pk = openssl_get_privatekey("file://../../id_rsa");
if ($pk === FALSE)
  error("Failed to read private key");
$signature = '';
$success = openssl_sign($data, $signature, $pk, OPENSSL_ALGO_SHA256);
if ($success === FALSE)
  error("Failed to sign ballot.");
$ballot['signature'] = base64_encode($signature);
$data = json_encode($ballot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
# publish ballot
$opts = array('http' => array('method' => 'POST', 'header' => 'Content-type: application/json', 'content' => $data));
$context = stream_context_create($opts);
$response = file_get_contents('https://notary.directdemocracy.vote/api/publish.sh', false, $context);
if ($reponse === false)
  error('Bad response from notary for publication of ballot');
$r = json_decode($response);
if (isset($r['error']))
  error($r['error']);
die($data);
?>