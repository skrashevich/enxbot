<?php

//
// config.php
//
//$mysql_server = '';
//$mysql_user = '';
//$mysql_pass = '';
//$mysql_db = '';

$db = mysqli_connect($mysql_server, $mysql_user, $mysql_pass);
mysqli_select_db($db, $mysql_db);
mysqli_query($db, 'set names utf8');

unset($mysql_pass);