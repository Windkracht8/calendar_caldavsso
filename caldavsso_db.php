<?php

class caldavsso_db{
	private static $instance;
	private $username;
	private $rc;
	private $dbh;
	private $prefix;
	
	static function get_instance(){
		if(!self::$instance){self::$instance = new caldavsso_db();}
		return self::$instance;
	}
	private function __construct(){
		$this->rc = rcube::get_instance();
		$this->username = $this->rc->get_user_name();
		$this->dbh = rcmail::get_instance()->db;
		$this->prefix = $this->rc->config->get("db_prefix", "");
	}

	public function get_cal($cal_id){
		$sql = "SELECT * FROM ".$this->prefix."calendar_caldavsso_cals WHERE username = ? AND cal_id = ?;";
		$sql_result = $this->dbh->query($sql, array($this->username, $cal_id));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		$cal = $this->dbh->fetch_assoc($sql_result);
		if($cal['dav_sso'] == 1){
			$cal['dav_user'] = $this->username;
			$cal['dav_pass'] = $this->rc->get_user_password();
		}else{
			$cal['dav_pass'] = $this->rc->decrypt($cal['dav_pass']);
		}
		return $cal;
	}
	public function del_cal($cal_id){
		$sql = "DELETE FROM ".$this->prefix."calendar_caldavsso_cals WHERE username = ? AND cal_id = ?;";
		$sql_result = $this->dbh->query($sql, array($this->username, $cal_id));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return true;
	}
	public function del_user($username){
		$sql = "DELETE FROM ".$this->prefix."calendar_caldavsso_cals WHERE username = ?;";
		$sql_result = $this->dbh->query($sql, array($username));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return true;
	}
	public function get_cals(){
		$sql = "SELECT * FROM ".$this->prefix."calendar_caldavsso_cals WHERE username = ?;";
		$sql_result = $this->dbh->query($sql, array($this->username));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		for($cals = array(); $temp = $this->dbh->fetch_assoc($sql_result);){$cals[] = $temp;}
		return $cals;
	}
	public function set_cal_data($cal_id, $name, $color, $showalarms, $dav_url, $dav_sso, $dav_user, $dav_pass, $dav_readonly){
		if(!$cal_id){$cal_id = $this->incr_cal_id();if(!$cal_id){return false;}}
		$dav_pass_enc = $this->rc->encrypt($dav_pass);
		$sql = "INSERT INTO ".$this->prefix."calendar_caldavsso_cals "
					."(username, cal_id, name, color, showalarms, dav_url, dav_sso, dav_user, dav_pass, dav_readonly) "
					."VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
					."ON DUPLICATE KEY UPDATE name=VALUES(name), color=VALUES(color), showalarms=VALUES(showalarms)"
						.", dav_url=VALUES(dav_url), dav_sso=VALUES(dav_sso), dav_user=VALUES(dav_user), dav_pass=VALUES(dav_pass), dav_readonly=VALUES(dav_readonly);";
		$sql_result = $this->dbh->query($sql, array($this->username, $cal_id, $name, $color, $showalarms, $dav_url, $dav_sso, $dav_user, $dav_pass_enc, $dav_readonly));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
		return true;
	}
	private function incr_cal_id(){
		$sql = "SELECT MAX(cal_id) AS max FROM ".$this->prefix."calendar_caldavsso_cals WHERE username = ?;";
		$sql_result = $this->dbh->query($sql, array($this->username));
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return null;}
		$sql_max = $this->dbh->fetch_assoc($sql_result);
		return $sql_max['max'] + 1;
	}

	private function handle_error($error){
		if(strpos($error, "Table") !== false
			&& strpos($error, "doesn't exist") !== false
		){
			if(strpos($error, "calendar_caldavsso_cals") !== false){
				rcube::raise_error(array('code' => 600, 'type' => 'db', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Sync table does not exist, I will create it now"), true, false);
				$this->create_table_calsync();
			}else{
				rcube::raise_error(array('code' => 600, 'type' => 'db', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Unkown table does not exists: $error"), true, false);
			}
		}else{
			rcube::raise_error(array('code' => 600, 'type' => 'db', 'file' => __FILE__, 'line' => __LINE__, 'message' => "Error while executing db query: $error"), true, false);
		}
	}

	private function create_table_calsync(){
		$create_db_sync = "CREATE TABLE IF NOT EXISTS ".
				$this->prefix."calendar_caldavsso_cals(".
				"username VARCHAR(255),cal_id INT".
				",name VARCHAR(255),color VARCHAR(255),showalarms INT".
				",dav_url VARCHAR(255),dav_sso INT,dav_user VARCHAR(255),dav_pass VARCHAR(255),dav_readonly INT".
				",UNIQUE KEY unique_index(username,cal_id));";
		$sql_result = $this->dbh->query($create_db_sync);
		if($db_error = $this->dbh->is_error($sql_result)){$this->handle_error($db_error);return false;}
	}

}