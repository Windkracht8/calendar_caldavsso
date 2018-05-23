<?php

require_once (dirname(__FILE__).'/caldavsso_db.php');
require_once (dirname(__FILE__).'/caldavsso_converters.php');

use Sabre\VObject;

class caldavsso_dav{
	public static function upd_event_time($driver_event){
		$cal = caldavsso_db::get_instance()->get_cal($driver_event['calendar']);
		if(isset($cal['dav_readonly']) && $cal['dav_readonly'] == 1){return false;}

		list($id, $id_rec, $id_full) = self::grab_ids($driver_event['id']);

		$vcal = self::get_dav_vcal($cal, $id);
		$vevent = clone $vcal->VEVENT;

		caldavsso_converters::updateDates($vevent, $driver_event);

		switch($driver_event['_savemode']){
			case "future":
				foreach($vcal->VEVENT as $vevent_loop){
					if($vevent_loop->{'RECURRENCE-ID'} == $id_rec || $vevent_loop->{'RECURRENCE-ID'} == substr($id_rec, 0, 8)){
						caldavsso_converters::updateDates($vevent_loop, $driver_event);
						$vevent_loop->{'RECURRENCE-ID'}['RANGE'] = 'THISANDFUTURE';
						break 2;
					}
				}
				unset($vevent->RRULE);
				unset($vevent->EXDATE);
				if(strlen((string)$vcal->VEVENT->DTSTART) == 8){
					$vevent->{'RECURRENCE-ID'} = substr($id_rec, 0, 8);
					$vevent->{'RECURRENCE-ID'}['VALUE'] = 'DATE';
				}else{
					$vevent->{'RECURRENCE-ID'} = $id_rec;
				}
				$vevent->{'RECURRENCE-ID'}['RANGE'] = 'THISANDFUTURE';
				$vcal->add($vevent);
				break;
			case "current":
				foreach($vcal->VEVENT as $vevent_loop){
					if($vevent_loop->{'RECURRENCE-ID'} == $id_rec || $vevent_loop->{'RECURRENCE-ID'} == substr($id_rec, 0, 8)){
						caldavsso_converters::updateDates($vevent_loop, $driver_event);
						break 2;
					}
				}
				unset($vevent->RRULE);
				unset($vevent->EXDATE);
				if(strlen((string)$vcal->VEVENT->DTSTART) == 8){
					$vevent->{'RECURRENCE-ID'} = substr($id_rec, 0, 8);
					$vevent->{'RECURRENCE-ID'}['VALUE'] = 'DATE';
				}else{
					$vevent->{'RECURRENCE-ID'} = $id_rec;
				}
				$vcal->add($vevent);
				break;
			case "all":
			default:
				$vcal->VEVENT = $vevent;
				break;
		}
		caldavsso_converters::addTimezone($vcal);
		$vcal->PRODID = caldavsso_driver::PRODID;

		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
		
		$response = self::makeRequest($cal['dav_url']."/".$id, 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "204"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to post event to server: ".$response->raw_body), true, true);
		}

		return true;
	}
	
	public static function upd_event($driver_event){
		$cal = caldavsso_db::get_instance()->get_cal($driver_event['calendar']);
		if(!isset($cal['dav_url'])){rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);}
		if(isset($cal['dav_readonly']) && $cal['dav_readonly'] == 1){return false;}

		list($id, $id_rec, $id_full) = self::grab_ids($driver_event['id']);

		$vcal = self::get_dav_vcal($cal, $id);
		$vevent = caldavsso_converters::driver2vevent($driver_event);
		$vevent->UID = $vcal->VEVENT->UID;

		if((string)$vcal->VEVENT->DTSTART != (string)$vevent->DTSTART || 
			(string)$vcal->VEVENT->DTEND != (string)$vevent->DTEND){
			//reset attendee status because the start and/or end time has changed
			foreach($vevent->ATTENDEE as $attendee){$attendee['PARTSTAT'] = "NEEDS-ACTION";}
		}
		
		switch($driver_event['_savemode']){
			case "future":
				foreach($vcal->VEVENT as $id => $vevent_loop){
					if($vevent_loop->{'RECURRENCE-ID'} == $id_rec || $vevent_loop->{'RECURRENCE-ID'} == substr($id_rec, 0, 8)){
						$vcal->remove($vevent_loop);
						break;
					}
				}
				if(strlen((string)$vevent->DTSTART) == 8){
					$vevent->{'RECURRENCE-ID'} = substr($id_rec, 0, 8);
					$vevent->{'RECURRENCE-ID'}['VALUE'] = 'DATE';
				}else{
					$vevent->{'RECURRENCE-ID'} = $id_rec;
				}
				$vevent->{'RECURRENCE-ID'}['RANGE'] = 'THISANDFUTURE';
				$vcal->add($vevent);
				break;
			case "current":
				foreach($vcal->VEVENT as $vevent_loop){
					if($vevent_loop->{'RECURRENCE-ID'} == $id_rec || $vevent_loop->{'RECURRENCE-ID'} == substr($id_rec, 0, 8)){
						$vcal->remove($vevent_loop);
						break;
					}
				}
				if(strlen((string)$vevent->DTSTART) == 8){
					$vevent->{'RECURRENCE-ID'} = substr($id_rec, 0, 8);
					$vevent->{'RECURRENCE-ID'}['VALUE'] = 'DATE';
				}else{
					$vevent->{'RECURRENCE-ID'} = $id_rec;
				}
				$vcal->add($vevent);
				break;
			case "all":
			default:
				$vcal->VEVENT = $vevent;
				break;
		}
		caldavsso_converters::addTimezone($vcal);
		$vcal->PRODID = caldavsso_driver::PRODID;

		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');

		$response = self::makeRequest($cal['dav_url']."/".$id, 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "204"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to post update to server: ".$response->raw_body), true, true);
		}

		return true;
	}
	
	public static function del_event($driver_event){
		$cal = caldavsso_db::get_instance()->get_cal($driver_event['calendar']);
		if(!isset($cal['dav_url'])){rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);}
		if(isset($cal['dav_readonly']) && $cal['dav_readonly'] == 1){return false;}

		list($id, $id_rec, $id_full) = self::grab_ids($driver_event['id']);
		
		switch($driver_event['_savemode']){
			case "future":
				$vcal = self::get_dav_vcal($cal, $id);
				$rrule = (string)$vcal->VEVENT->RRULE;
				$rrule = preg_replace(array("/COUNT=.*;/", "/;COUNT=.*/"), "", $rrule);
				
				//Substract one day
				$id_rec_datetime = new DateTime($id_rec);
				$id_rec_datetime->sub(new DateInterval('P1D'));
				$id_rec = $id_rec_datetime->format('Ymd\THis\Z');
				
				//Set same format as DTSTART
				$id_rec = substr($id_rec, 0, strlen($vcal->VEVENT->DTSTART));

				$rrule .= ";UNTIL=$id_rec";
				$vcal->VEVENT->RRULE = $rrule;
				$vcal->PRODID = caldavsso_driver::PRODID;

				$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
				$response = self::makeRequest($cal['dav_url']."/".$id, 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
				if($response->code != "204"){
					rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to delete on server: ".$response->raw_body), true, true);
				}
				return true;
			case "current":
				$vcal = self::get_dav_vcal($cal, $id);
				$vevent = $vcal->VEVENT;

				if((strlen($vevent->DTSTART)) == 8){
					$exdates = substr($id_rec, 0, 8);
					if(isset($vevent->EXDATE)){
						$exdates .= ",".$vevent->EXDATE;
						unset($vcal->VEVENT->EXDATE);
					}
					$vcal->VEVENT->add('EXDATE', $exdates, ['VALUE' => 'DATE']);
				}else{
					$exdates = $id_rec;
					if(isset($vevent->EXDATE)){
						$exdates .= ",".$vevent->EXDATE;
					}
					$vcal->VEVENT->EXDATE = $exdates;
				}
				
				$vcal->PRODID = caldavsso_driver::PRODID;
				$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
				$response = self::makeRequest($cal['dav_url']."/".$id, 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
				if($response->code != "204"){
					rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to delete on server: ".$response->raw_body), true, true);
				}
				return true;
			case "all":
				break;
		}

		$response = self::makeRequest($cal['dav_url']."/".$id, 'DELETE', "", "", $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "204"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to delete on server ".$cal['dav_url']."/".$href.": ".$response->raw_body), true, true);
		}

		return true;
	}
	
	public static function update_attendees($driver_event, $attendees){
		$cal = caldavsso_db::get_instance()->get_cal($driver_event['calendar']);
		if(isset($cal['dav_readonly']) && $cal['dav_readonly'] == 1){return false;}
		list($id, $id_rec, $id_full) = self::grab_ids($driver_event['id']);
		$vcal = self::get_dav_vcal($cal, $id);
		
		foreach($attendees as $attendee){
			$vattendees = $vcal->VEVENT->select("ATTENDEE");
			foreach($vattendees as $vattendee){
				$email = (string)$vattendee;
				$email = substr($email, 0, 7) == "mailto:" ? substr($email, 7) : $email;
				if($email == $attendee['email']){
					$vattendee['PARTSTAT'] = $attendee['status'];
					$vattendee['CN'] = $attendee['name'];
				}
			}
		}
		
		$vcal->PRODID = caldavsso_driver::PRODID;
		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
		$response = self::makeRequest($cal['dav_url']."/".$id, 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "204"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to post event to server: ".$response->raw_body), true, true);
		}
		
		return true;
	}
	
	public static function create_event($driver_event){
		$cal = caldavsso_db::get_instance()->get_cal($driver_event['calendar']);
		if(!isset($cal['dav_url'])){rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);}
		if(isset($cal['dav_readonly']) && $cal['dav_readonly'] == 1){return false;}

		$uid = self::generateUID($cal, $driver_event['uid']);
		
		$vcal = new VObject\Component\VCalendar;
		$vevent = caldavsso_converters::driver2vevent($driver_event);
		$vevent->UID = $uid;
		$vcal->add($vevent);
		caldavsso_converters::addTimezone($vcal);
		$vcal->PRODID = caldavsso_driver::PRODID;

		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
		
		$response = self::makeRequest($cal['dav_url']."/".$uid.".ics", 'PUT', $headers, $vcal->serialize(), $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "201"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to create event on server: ".$response->raw_body), true, true);
		}

		return true;
	}
	
	public static function get_dav_vcal_id($cal_id, $id_mixed){
		$cal = caldavsso_db::get_instance()->get_cal($cal_id);
		list($id, $id_rec, $id_full) = self::grab_ids($id_mixed);
		return self::get_dav_vcal($cal, $id);
	}
	public static function get_dav_vcal($cal, $id){
		if(!isset($cal['dav_url'])){rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);}
		
		$response = self::makeRequest($cal['dav_url']."/".$id, 'GET', "", "", $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "200"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to get event from server: ".$response->raw_body), true, true);
		}

		try{
			$dav_vcal = VObject\Reader::read($response->raw_body, VObject\Reader::OPTION_FORGIVING);
		}catch(Exception $e){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to parse vobject: ".$e->getMessage(), true, true));
		}
		return $dav_vcal;
	}
	
	public static function get_events($start, $end, $query, $cal_id, $virtual, $modifiedsince){
		$start_zulu = date("Ymd\THis\Z", $start);
		$end_zulu = date("Ymd\THis\Z", $end);
		
		$headers = array('Content-type'=>'text/xml; charset="utf-8"', 'Depth'=>'1');
		$body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
				.'<d:prop><c:calendar-data /></d:prop>'
				.'<c:filter>'
					.'<c:comp-filter name="VCALENDAR">'
						.'<c:comp-filter name="VEVENT">'
							.'<c:time-range start="'.$start_zulu.'" end="'.$end_zulu.'"/>';
		if($query){
			$body .=		'<c:prop-filter name="SUMMARY">'
								.'<c:text-match match-type="contains">'.$query.'</c:text-match>'
							.'</c:prop-filter>';
		}
		if($modifiedsince){
			$modifiedsince_zulu = date("Ymd\THis\Z", $modifiedsince);
			$body .=		'<c:prop-filter name="DTSTAMP">'
								.'<c:time-range start="'.$modifiedsince_zulu.'" />'
							.'</c:prop-filter>';
		}
		$body .=		'</c:comp-filter>'
					.'</c:comp-filter>'
				.'</c:filter>'
				// TODO: query in DESCRIPTION, needs multiple queries
				.'</c:calendar-query>';

		$cal = caldavsso_db::get_instance()->get_cal($cal_id);
		if(!isset($cal['dav_url'])){
			rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);
		}

		$response = self::makeRequest($cal['dav_url'], 'REPORT', $headers, $body, $cal['dav_user'], $cal['dav_pass']);
		if($response->code != "207"){
			rcube::raise_error(array('code' => $response->code, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to get events from server: ".$response->raw_body), true, true);
		}

		$xmlDoc = new DOMDocument();
		if(!$xmlDoc->loadXML($response->raw_body)){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to interpret response from server", true, true));
		}
		$driver_events = array();
		$responses = $xmlDoc->getElementsByTagName('response');
		foreach($responses as $response){
			$hrefs = $response->getElementsByTagName('href');
			$href = $hrefs[0]->nodeValue;
			
			$calendar_datas = $response->getElementsByTagName('calendar-data');
			$calendar_data = $calendar_datas[0]->nodeValue;

			try{
				$vcal = VObject\Reader::read($calendar_data, VObject\Reader::OPTION_FORGIVING);
				$RRULE = (string)$vcal->VEVENT->RRULE;
				$vcal->expand(new DateTime($start_zulu), new DateTime($end_zulu));
			}catch(Exception $e){
				rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "failed to parse vobject: ".$e->getMessage(), true, true));
			}

			foreach($vcal->children as $vevent){
				if($vevent->name != "VEVENT"){continue;}
				$driver_events[] = caldavsso_converters::vevent2driver($vevent, $cal_id, $href, $RRULE);
			}
		}
		return $driver_events;
	}
	
	public static function generateUID($cal, $uid = null){
		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
		$uid = $uid == null ? uniqid() : $uid;
		$response = self::makeRequest($cal['dav_url']."/".$uid.".ics", 'GET', $headers, "", $cal['dav_user'], $cal['dav_pass']);
		if($response->code == "404"){return $uid;}
		return self::generateUID($cal);
	}

	public static function grab_ids($id_mixed){
		if(strlen($id_mixed) < 5){
			rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "too small identifier: '$id_mixed'"), false, true);
		}
		$id_rec = null;
		$id = $id_mixed;
		if(strpos($id_mixed, ".ics/")){
			// It's an id with a recurrence identifier
			$id_rec = substr($id, strrpos($id, "/")+1);
			$id = substr($id, 0, strrpos($id, "/"));
		}
		$id = strrpos($id, '/') ? substr($id, strrpos($id, '/')+1) : $id; // Strip everything before the last /
		$id = substr($id, -4) == ".ics" ? $id : $id.".ics"; // Append .ics if it's not there at the end
		$id_full = $id_rec == null ? $id : $id."/".$id_rec;
		return array($id, $id_rec, $id_full);
	}

	// Used by driver to first check if event exists
	public static function does_exists($cal_id, $id_mixed){
		list($id, $id_rec, $id_full) = self::grab_ids($id_mixed);
		$cal = caldavsso_db::get_instance()->get_cal($cal_id);
		if(!isset($cal['dav_url'])){rcube::raise_error(array('code' => 404, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "no dav url"), true, true);}
		$headers = array('Content-type'=>'text/calendar; charset="utf-8"');
		$response = self::makeRequest($cal['dav_url']."/".$id, 'GET', $headers, "", $cal['dav_user'], $cal['dav_pass']);
		return $response->code == "200";
	}

	public static function makeRequest($url, $method, $headers, $body, $user, $pass){
		$httpful = \Httpful\Request::init();
		$httpful->basicAuth($user, $pass);
		$httpful->addHeader("User-Agent", "roundcube_caldavsso");
		$httpful->uri($url);
		$httpful->method($method);
		if(is_array($headers)){
			foreach($headers as $name => $value){
				$httpful->addHeader($name, $value);
			}
		}
		$httpful->body($body);
		return $httpful->send();
	}
}