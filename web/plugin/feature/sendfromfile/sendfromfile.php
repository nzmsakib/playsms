<?php

/**
 * This file is part of playSMS.
 *
 * playSMS is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * playSMS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with playSMS. If not, see <http://www.gnu.org/licenses/>.
 */
defined('_SECURE_') or die('Forbidden');

if (!auth_isvalid()) {
	auth_block();
}

switch (_OP_) {
	case 'list':
		$content = _dialog() . '<h2>' . _('Send from file') . '</h2><p />';
		if (auth_isadmin()) {
			$info_format = _('destination number, message, username');
		} else {
			$info_format = _('destination number, message');
		}
		$content .= "
			<table class=ps_table>
				<tbody>
					<tr>
						<td>
							<form action=\"index.php?app=main&inc=feature_sendfromfile&op=upload_confirm\" enctype=\"multipart/form-data\" method=\"post\">
							" . _CSRF_FORM_ . "
							<p>" . _('Please select CSV file') . "</p>
							<p><input type=\"file\" name=\"fncsv\"></p>
							<p class=help-block>" . _('CSV file format') . " : " . $info_format . "</p>
							<p><input type=\"submit\" value=\"" . _('Upload file') . "\" class=\"button\"></p>
							</form>
						</td>
					</tr>
				</tbody>
			</table>";
		_p($content);
		break;
	case 'upload_confirm':
		@set_time_limit(0);

		// fixme anton - https://www.exploit-database.net/?id=92843
		$filename = core_sanitize_filename($_FILES['fncsv']['name']);
		if ($filename == $_FILES['fncsv']['name']) {
			$continue = TRUE;
		} else {
			$continue = FALSE;
		}

		$fn = $_FILES['fncsv']['tmp_name'];
		$fs = (int) $_FILES['fncsv']['size'];
		$all_numbers = [];
		$valid = 0;
		$invalid = 0;
		$item_invalid = [];

		if ($continue && ($fs == filesize($fn)) && file_exists($fn)) {
			if (($fd = fopen($fn, 'r')) !== FALSE) {
				$sid = md5(uniqid('SID', true));
				$continue = true;
				$fc = [];
				while ((($row = fgetcsv($fd, $fs, ',')) !== FALSE) && $continue) {
					$fc[] = $row;
				}
				fclose($fd);

				foreach ( $fc as $data ) {
					$dup = false;
					$data[0] = isset($data[0]) ? trim($data[0]) : '';
					$data[1] = isset($data[1]) ? trim($data[1]) : '';
					$data[2] = isset($data[2]) ? trim($data[2]) : '';
					$sms_to = $data[0];
					$sms_msg = $data[1];
					$sms_username = $data[2];
					if (auth_isadmin()) {
						if ($uid = user_username2uid($data[2])) {
							$sms_username = $data[2];
						} else {
							$sms_username = $user_config['username'];
							$uid = $user_config['uid'];
						}
					} else {
						$sms_username = $user_config['username'];
						$uid = $user_config['uid'];
						$data[2] = $sms_username;
					}

					// check dups
					$dup = (in_array($sms_to, $all_numbers) ? TRUE : FALSE);

					if ($sms_to && $sms_msg && $sms_username && $uid && !$dup) {
						$all_numbers[] = $sms_to;
						$db_query = "INSERT INTO " . _DB_PREF_ . "_featureSendfromfile (uid,sid,sms_datetime,sms_to,sms_msg,sms_username) ";
						$db_query .= "VALUES ('$uid','$sid','" . core_get_datetime() . "','$sms_to','" . addslashes($sms_msg) . "','$sms_username')";
						if (dba_insert_id($db_query)) {
							$valid++;
						} else {
							$item_invalid[$invalid] = $data;
							$invalid++;
						}
					} else if ($sms_to || $sms_msg) {
						$item_invalid[$invalid] = $data;
						$invalid++;
					}
					$num_of_rows = $valid + $invalid;
					if ($num_of_rows >= (int) $sendfromfile_row_limit) {
						break;
					}
				}

				unset($fc);
			} else {
				$_SESSION['dialog']['danger'][] = _('Unable to open uploaded CSV file');
				header("Location: " . _u('index.php?app=main&inc=feature_sendfromfile&op=list'));
				exit();
			}
		} else {
			$_SESSION['dialog']['danger'][] = _('Invalid CSV file');
			header("Location: " . _u('index.php?app=main&inc=feature_sendfromfile&op=list'));
			exit();
		}

		$content = '<h2>' . _('Send from file') . '</h2><p />';
		$content .= '<h3>' . _('Confirmation') . '</h3><p />';
		$content .= _('Uploaded file') . ': ' . $filename . '<p />';

		if ($valid) {
			$content .= _('Found valid entries in uploaded file') . ' (' . _('valid entries') . ': ' . $valid . ' ' . _('of') . ' ' . $num_of_rows . ')<p />';
		}

		if ($invalid) {
			$content .= '<p /><br />';
			$content .= _('Found invalid entries in uploaded file') . ' (' . _('invalid entries') . ': ' . $invalid . ' ' . _('of') . ' ' . $num_of_rows . ')<p />';
			$content .= '<h4>' . _('Invalid entries') . '</h4>';
			$content .= "
				<div class=table-responsive>
				<table class=playsms-table-list>
				<thead><tr>
					<th width='20%'>" . _('Destination number') . "</th>
					<th width='60%'>" . _('Message') . "</th>
					<th width='20%'>" . _('Username') . "</th>
				</tr></thead>";
			$j = 0;
			foreach ( $item_invalid as $item ) {
				if ($item[0] && $item[1] && $item[2]) {
					$content .= "
						<tr>
							<td>" . $item[0] . "</td>
							<td>" . $item[1] . "</td>
							<td>" . $item[2] . "</td>
						</tr>";
				}
			}
			$content .= "</tbody></table></div>";
		}

		$content .= '<h4>' . _('Your choice') . '</h4><p />';
		$content .= "<form action=\"index.php?app=main&inc=feature_sendfromfile&op=upload_cancel\" method=\"post\">";
		$content .= _CSRF_FORM_ . "<input type=hidden name=sid value='" . $sid . "'>";
		$content .= "<input type=\"submit\" value=\"" . _('Cancel send from file') . "\" class=\"button\"></p>";
		$content .= "</form>";
		$content .= "<form action=\"index.php?app=main&inc=feature_sendfromfile&op=upload_process\" method=\"post\">";
		$content .= _CSRF_FORM_ . "<input type=hidden name=sid value='" . $sid . "'>";
		$content .= "<input type=\"submit\" value=\"" . _('Send SMS to valid entries') . "\" class=\"button\"></p>";
		$content .= "</form>";

		_p($content);
		break;

	case 'upload_cancel':
		if ($sid = $_REQUEST['sid']) {
			$db_query = "DELETE FROM " . _DB_PREF_ . "_featureSendfromfile WHERE sid='$sid'";
			dba_query($db_query);
			$_SESSION['dialog']['danger'][] = _('Send from file has been cancelled');
		} else {
			$_SESSION['dialog']['danger'][] = _('Invalid session ID');
		}
		header("Location: " . _u('index.php?app=main&inc=feature_sendfromfile&op=list'));
		exit();

	case 'upload_process':
		@set_time_limit(0);
		if ($sid = $_REQUEST['sid']) {
			$db_query = "SELECT count(*) as count FROM " . _DB_PREF_ . "_featureSendfromfile WHERE sid='$sid'";
			$db_result = dba_query($db_query);
			$db_row = dba_fetch_array($db_result);
			$count = (int) $db_row['count'];

			$data = array();
			$db_query = "SELECT * FROM " . _DB_PREF_ . "_featureSendfromfile WHERE sid='$sid'";
			$db_result = dba_query($db_query);
			$i = 0;
			while ($db_row = dba_fetch_array($db_result)) {
				$c_sms_to = $db_row['sms_to'];
				$c_username = $db_row['sms_username'];
				$c_sms_msg = $db_row['sms_msg'];
				$c_hash = md5($c_username . $c_sms_msg);
				if ($c_sms_to && $c_username && $c_sms_msg) {
					$data[$c_hash]['username'] = $c_username;
					$data[$c_hash]['message'] = $c_sms_msg;
					$data[$c_hash]['sms_to'][] = $c_sms_to;
				}
			}
			foreach ( $data as $hash => $item ) {
				$username = $item['username'];
				$message = $item['message'];
				$sms_to = $item['sms_to'];
				_log('hash:' . $hash . ' u:' . $username . ' m:[' . $message . '] to_count:' . count($sms_to), 3, 'sendfromfile upload_process');
				if ($username && $message && count($sms_to)) {
					$type = 'text';
					$unicode = core_detect_unicode($message);
					$message = addslashes($message);
					list($ok, $to, $smslog_id, $queue) = sendsms_helper($username, $sms_to, $message, $type, $unicode);
				}
			}
			$db_query = "DELETE FROM " . _DB_PREF_ . "_featureSendfromfile WHERE sid='$sid'";
			dba_query($db_query);
			$_SESSION['dialog']['info'][] = _('SMS has been sent to valid numbers in uploaded file');
		} else {
			$_SESSION['dialog']['danger'][] = _('Invalid session ID');
		}
		header("Location: " . _u('index.php?app=main&inc=feature_sendfromfile&op=list'));
		exit();
}
