<?php
$conn = new mysqli("localhost", "root", "", "dolgapp");
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}
$conn->set_charset("utf8");
?>
