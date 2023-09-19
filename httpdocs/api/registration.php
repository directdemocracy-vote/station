<?php
require_once '../../php/database.php';

function error($message) {
  die("{\"error\":\"$message\"}");
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");
$registration = json_decode(file_get_contents("php://input"));

if (!$registration)
  error("Unable to parse JSON post");
if (!isset($registration->schema))
  error("Unable to read registration schema field");
if ($registration->schema != "https://directdemocracy.vote/json-schema/0.0.2/registration.schema.json")
  error("Wrong registration schema");
if (!isset($registration->signature))
  error("No registration signature");
$signature = $registration->signature;
$registration->signature = '';
$data = json_encode($registration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$verify = openssl_verify($data, base64_decode($signature), public_key($registration->key), OPENSSL_ALGO_SHA256);
if ($verify != 1)
  error("Wrong registration signature");

$mysqli = new mysqli($database_host, $database_username, $database_password, $database_name);
if ($mysqli->connect_errno)
  error("Failed to connect to MySQL database: $mysqli->connect_error ($mysqli->connect_errno)");
$mysqli->set_charset('utf8mb4');
$participation = $mysqli->escape_string($registration->participation);
$query = "SELECT referendumFingerprint, privateKey FROM participation WHERE publicKey='$participation'";
$result = $mysqli->query($query) or error($mysqli->error);
$participation = $result->fetch_assoc();
if (!$participation)
  error("Participation not found");
file_get_contents('https://notary.directdemocracy.vote/api/proposal.php?fingerprint=')
