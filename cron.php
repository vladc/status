<?php
/*
	This file is part of the status project.

    The status project is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    The status project is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with the status project.  If not, see <http://www.gnu.org/licenses/>.
*/
	if (isset($argv[1])) $conf = $argv[1]; else $conf = "config.php";
	require $conf;
	require 'Net/Ping.php';

	try {
		$db = new PDO('sqlite:' . $db);
	} catch (PDOException $e) {
		die('Unable to connect to the database.'. $e);
	}

	$dbs = $db->prepare("SELECT * FROM servers WHERE disabled = 0");
	$result = $dbs->execute();
	if ($result) {
		$ra = $dbs->fetchAll(PDO::FETCH_ASSOC);
		foreach ($ra as $i => $row) {
			if(empty($row['solusvm_url']) || empty($row['solusvm_key']) || empty($row['solusvm_hash'])) {
				$bw = false;
			} else if((time() - $row['time']) > $solus_frequency) {
				$bw = fetch_solus($row['solusvm_url'], $row['solusvm_key'], $row['solusvm_hash']);
			} else {
				$bw = array($row['bwtotal'], $row['bwused'], $row['bwfree']);
			}
			list($bwtotal, $bwused, $bwfree) = ($bw == false ? array(NULL, NULL, NULL) : $bw);
			$fp = @fsockopen($row['hostname'], $port, $errno, $errstr, 5);
			if (!$fp) {
				$ping = Net_Ping::factory();
				$ping->setArgs(array('count' => 8));
				$pr = $ping->ping($row['hostname']);
				if ($pr->_loss == "0") {
					updateserver(1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $bwtotal, $bwused, $bwfree, $row['uid']);
					if (isset($email) && $row['status'] == '0') {
						mail($email, 'Server '. $row['uid'] .' is up!', 'Server '. $row['uid'] .' on node '. $row['node'] .' is up. That rocks!');
					}
				} else {
					updateserver(0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $bwtotal, $bwused, $bwfree, $row['uid']);
					if (isset($email) && $row['status'] == '1') {
						mail($email, 'Server '. $row['uid'] .' is down', 'Server '. $row['uid'] .' on node '. $row['node'] .' is down. That sucks!');
					}
				}
			} else {
				$result = fgets($fp, 2048);
				@fclose($fp);
				$result = json_decode($result, true);
				updateserver(1, $result['uplo']['uptime'], $result['ram']['total'], $result['ram']['used'], $result['ram']['free'], $result['ram']['bufcac'], $result['disk']['total']['total'], $result['disk']['total']['used'], $result['disk']['total']['avail'], $result['uplo']['load1'], $result['uplo']['load5'], $result['uplo']['load15'], $bwtotal, $bwused, $bwfree, $row['uid']);
				if (isset($email) && $row['status'] == "0") {
					mail($email, 'Server '. $row['uid'] .' is up', 'Server '. $row['uid'] .' on node '. $row['node'] .' is up. That rocks!');
				}
			}
		}
	}

	function updateserver($status, $uptime, $mtotal, $mused, $mfree, $mbuffers, $disktotal, $diskused, $diskfree, $load1, $load5, $load15, $bwtotal, $bwused, $bwfree, $uid) {
		global $db;
		try {
			$dbs = $db->prepare('UPDATE servers SET time = ?, status = ?, uptime = ?, mtotal = ?, mused = ?, mfree = ?, mbuffers = ?, disktotal = ?, diskused = ?, diskfree = ?, load1 = ?, load5 = ?, load15 = ?, bwtotal = ?, bwused = ?, bwfree = ? WHERE uid = ?');
			$dbs->execute(array(time(), $status, $uptime, $mtotal, $mused, $mfree, $mbuffers, $disktotal, $diskused, $diskfree, $load1, $load5, $load15, $bwtotal, $bwused, $bwfree, $uid));
		} catch (PDOException $e) {
			echo $e;
			die('Something broke!');
		}

	}

	function fetch_solus($url, $key, $hash) {
		if (function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url.'/api/client/command.php');
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, 'key='.$key.'&hash='.$hash.'&action=info&bw=true');
			$data = curl_exec($ch);
			curl_close($ch);
		} else {
			$data = file_get_contents($url.'/api/client/command.php?key='.$key.'&hash='.$hash.'&action=info&bw=true');
		}
		if(!empty($data)) {
			preg_match_all('/<(.*?)>([^<]+)<\/\\1>/i', $data, $match);
			$result = array();
			foreach ($match[1] as $x => $y) {
				$result[$y] = $match[2][$x];
			}
			if($result['status'] == 'success') { 
				$bytes = explode(',', $result['bw']);
				return array(intval($bytes[0] / 1024), intval($bytes[1] / 1024), intval($bytes[2] / 1024)); // total,used,free (bits/1024)
			}
		}
		return false;
	}

?>

