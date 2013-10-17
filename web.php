<?php

$config = parse_ini_file("dg.ini", true);
$pdo = new PDO("mysql:host=".$config['mysql_misc']['host'].";dbname=".$config['mysql_misc']['db_name'], $config['mysql_misc']['user'], $config['mysql_misc']['password']);
$pdo2 = new PDO("mysql:host=".$config['mysql_openfire']['host'].";dbname=".$config['mysql_openfire']['db_name'], $config['mysql_openfire']['user'], $config['mysql_openfire']['password']);

$users_sql = $pdo2->prepare('select username,name from ofUser');
$a_groups_sql = $pdo->prepare('select groupName from of_broadcast_mapping where username = :un');
$ignore_sql = $pdo->prepare('select username from of_broadcast_ignore where username = :un');

$a_groups_sql->execute(array(':un'=>'shin_chogan'));
$ignore_sql->execute(array(':un'=>'shin_chogan'));
$users_sql->execute();
$a_group_list = $a_groups_sql->fetchAll(PDO::FETCH_COLUMN, 0);
$ignore = $ignore_sql->fetchAll(PDO::FETCH_COLUMN, 0);
$users_list = $users_sql->fetchAll();

echo "<html><body><table border=1>\n";
foreach ($users_list as $user)
{
	echo "<tr><td>".$user[0]."</td><td>".$user[1]."</td><tr>\n";
}
echo "</table>\n";

//var_dump($users_list);

var_dump($a_group_list);
var_dump($ignore);
?>