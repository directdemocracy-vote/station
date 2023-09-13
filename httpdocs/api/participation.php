<?php
require_once '../php/database.php';

function error($message) {
  die("{\"error\":\"$message\"}");
}

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: content-type");

error("Not yet implemented");

?>