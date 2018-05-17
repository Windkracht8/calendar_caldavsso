<?php

use Sabre\VObject;

class caldavsso_converters{
	public static function driver2vevent($driver_event){
		$vcal = new VObject\Component\VCalendar;
		$vevent = $vcal->createComponent('VEVENT');
		
		if($driver_event['allday'] == 1){
			$vevent->DTSTART = gmdate("Ymd", $driver_event['start']->format('U'));
			$vevent->DTSTART['VALUE'] = 'DATE';
			$driver_event['end']->add(new DateInterval('P1D'));
			$vevent->DTEND = gmdate("Ymd", $driver_event['end']->format('U'));
			$vevent->DTEND['VALUE'] = 'DATE';
			$vevent->{'X-MICROSOFT-CDO-ALLDAYEVENT'} = 'TRUE';
		}else{
			$vevent->DTSTART = $driver_event['start']->format('Ymd\THis');
			$vevent->DTSTART['TZID'] = $driver_event['start']->getTimezone()->getName();
			$vevent->DTEND = $driver_event['end']->format('Ymd\THis');
			$vevent->DTEND['TZID'] = $driver_event['end']->getTimezone()->getName();
			$vevent->{'X-MICROSOFT-CDO-ALLDAYEVENT'} = 'FALSE';
		}
		
		if(isset($driver_event['title']) && $driver_event['title'] != ""){
			$vevent->SUMMARY = $driver_event['title'];
		}
		if(isset($driver_event['description']) && $driver_event['description'] != ""){
			$vevent->DESCRIPTION = $driver_event['description'];
		}
		if(isset($driver_event['location']) && $driver_event['location'] != ""){
			$vevent->LOCATION = $driver_event['location'];
		}

		if(isset($driver_event['free_busy'])){
			switch($driver_event['free_busy']){
				case "busy":
					$vevent->TRANSP = "OPAQUE";
					$vevent->{'X-MICROSOFT-CDO-INTENDEDSTATUS'} = "BUSY";
					break;
				case "free":
					$vevent->TRANSP = "TRANSPARENT";
					$vevent->{'X-MICROSOFT-CDO-INTENDEDSTATUS'} = "FREE";
					break;
				case "tentative":
					$vevent->TRANSP = "OPAQUE";
					$vevent->{'X-MICROSOFT-CDO-INTENDEDSTATUS'} = "TENTATIVE";
					break;
				case "outofoffice":
					$vevent->TRANSP = "TRANSPARENT";
					$vevent->{'X-MICROSOFT-CDO-INTENDEDSTATUS'} = "OOF";
					break;
			}
		}
		if(isset($driver_event['status']) && $driver_event['status'] != ""){$vevent->STATUS = $driver_event['status'];}
		
		if(isset($driver_event['sensitivity']) && $driver_event['sensitivity'] != ""){$vevent->CLASS = strtoupper($driver_event['sensitivity']);}
		if(isset($driver_event['priority']) && $driver_event['priority'] != ""){$vevent->PRIORITY = $driver_event['priority'];}
		
		if(isset($driver_event['attendees']) && is_array($driver_event['attendees'])){
			foreach($driver_event['attendees'] as $attendee){
				if($attendee['role'] == "ORGANIZER"){
					$vevent->add('ORGANIZER', "mailto:".$attendee['email'], ['CN' => $attendee['name']]);
				}else{
					$vevent->add('ATTENDEE', "mailto:".$attendee['email'], 
						['CN' => $attendee['name'],
							'ROLE' => $attendee['role'],
							'PARTSTAT' => $attendee['status'],
							'RSVP' => $attendee['rsvp'] ? "TRUE" : "FALSE"
						]);
				}
			}
		}

		if(isset($driver_event['valarms']) && is_array($driver_event['valarms'])){
			foreach($driver_event['valarms'] as $alarm){
				$valarm = $vcal->createComponent('VALARM');
				$valarm->add('ACTION', "DISPLAY");
				$valarm->add('TRIGGER', $alarm['trigger'], ['RELATED' => strtoupper($alarm['related'])]);
				$vevent->add($valarm);
			}
		}
		
		if(isset($driver_event['recurrence']) && is_array($driver_event['recurrence'])){
			$rrule = "FREQ=".$driver_event['recurrence']['FREQ'];
			if(isset($driver_event['recurrence']['COUNT'])){$rrule .= ";COUNT=".$driver_event['recurrence']['COUNT'];}
			if(isset($driver_event['recurrence']['UNTIL'])){
				if($driver_event['allday'] == 1){
					$rrule .= ";UNTIL=".gmdate("Ymd", $driver_event['recurrence']['UNTIL']->format('U'));
				}else{
					$rrule .= ";UNTIL=".gmdate("Ymd\THis\Z", $driver_event['recurrence']['UNTIL']->format('U'));
				}
			}
			$rrule .= ";INTERVAL=".$driver_event['recurrence']['INTERVAL'];
			if(isset($driver_event['recurrence']['BYDAY'])){$rrule .=";BYDAY=".$driver_event['recurrence']['BYDAY'];}
			
			$vevent->add('RRULE', $rrule);
		}
		
		$vevent->DTSTAMP = gmdate("Ymd\THis\Z");
		$vevent->{'LAST-MODIFIED'} = gmdate("Ymd\THis\Z");
		
		return $vevent;
	}
	
	public static function addTimezone(&$vcal){
		$vevent = $vcal->VEVENT;
		if(!isset($vevent->DTSTART['TZID'])){return;}

		$tzid = (string)$vevent->DTSTART['TZID'];
		if(!in_array($tzid, timezone_identifiers_list())){return;}

		if(isset($vcal->VTIMEZONE->TZID) && $vcal->VTIMEZONE->TZID == $tzid){return;}

		$year = substr((string)$vevent->DTSTART, 0, 4);

		$vtimezone = $vcal->createComponent('VTIMEZONE');
		$vtimezone->TZID = $tzid;
		$timezone = new DateTimeZone($tzid);
		$transitions = $timezone->getTransitions(date("U", strtotime($year."0101T000000Z")), date("U", strtotime($year."1231T235959Z")));

		$offset_from = self::phpOffsetToIcalOffset($transitions[0]['offset']);
		for ($i=0; $i<count($transitions); $i++) {
			$offset_to = self::phpOffsetToIcalOffset($transitions[$i]['offset']);
			if ($i == 0) {
					$offset_from = $offset_to;
				if (count($transitions) > 1) {
					continue;
				}
			}
			$vtransition = $vcal->createComponent($transitions[$i]['isdst'] == 1 ? "DAYLIGHT" : "STANDARD");
			
			$vtransition->TZOFFSETFROM = $offset_from;
			$vtransition->TZOFFSETTO = $offset_to;
			$offset_from = $offset_to;
			
			$vtransition->TZNAME = $transitions[$i]['abbr'];
			$vtransition->DTSTART = date("Ymd\THis", $transitions[$i]['ts']);
			$vtimezone->add($vtransition);
		}
		$vcal->add($vtimezone);
	}
	
	private static function phpOffsetToIcalOffset($phpoffset) {
		$prefix = $phpoffset < 0 ? "-" : "+";
		$offset = abs($phpoffset);
		$hours = floor($offset / 3600);
		return sprintf("$prefix%'.02d%'.02d", $hours, ($offset - ($hours * 3600)) / 60);
	}
	
	public static function updateDates(&$vevent, $driver_event){
		if($driver_event['allday'] == 1){
			$vevent->DTSTART = gmdate("Ymd", $driver_event['start']->format('U'));
			$vevent->DTSTART['VALUE'] = 'DATE';
			$driver_event['end']->add(new DateInterval('P1D'));
			$vevent->DTEND = gmdate("Ymd", $driver_event['end']->format('U'));
			$vevent->DTEND['VALUE'] = 'DATE';
			$vevent->{'X-MICROSOFT-CDO-ALLDAYEVENT'} = 'TRUE';
		}else{
			$vevent->DTSTART = $driver_event['start']->format('Ymd\THis');
			$vevent->DTSTART['TZID'] = $driver_event['start']->getTimezone()->getName();
			$vevent->DTEND = $driver_event['end']->format('Ymd\THis');
			$vevent->DTEND['TZID'] = $driver_event['end']->getTimezone()->getName();
			$vevent->{'X-MICROSOFT-CDO-ALLDAYEVENT'} = 'FALSE';
		}
		$vevent->DTSTAMP = gmdate("Ymd\THis\Z");
		$vevent->{'LAST-MODIFIED'} = gmdate("Ymd\THis\Z");
	}
	
	public static function vevent2driver($vevent, $cal_id, $id_mixed, $RRULE = null){
		$driver_event = array();
		$driver_event["calendar"] = $cal_id;
		list($id, $id_rec, $id_full) = caldavsso_dav::grab_ids($id_mixed);
		$driver_event["id"] = $id_full;
		$driver_event["allday"] = -1;
		$driver_event["free_busy"] = -1;
		$driver_event["attendees"] = array();

		if($RRULE){
			$rec_array = array();
			foreach(explode(";", $RRULE) as $prop){
				list($prop_key, $prop_value) = explode("=", $prop);
				switch($prop_key){
					case "UNTIL":
						$rec_array[$prop_key] = new DateTime($prop_value);
						break;
					default:
						$rec_array[$prop_key] = $prop_value;
						break;
				}
			}
			$driver_event["recurrence"] = $rec_array;
			$driver_event["isexception"] = 0;
		}
		
		foreach($vevent->children as $value){
			switch($value->name){
				case "DTSTART":
					$driver_event['start'] = (string)$value;
					break;
				case "DTEND":
					$driver_event['end'] = (string)$value;
					break;
				case "X-MICROSOFT-CDO-ALLDAYEVENT":
					$driver_event["allday"] = (string)$value == "TRUE" ? 1 : 0;
					break;
				case "TRANSP":
					if($driver_event["free_busy"] == -1){
						$driver_event["free_busy"] = (string)$value == "OPAQUE" ? 'busy' : 'free';
					}
					break;
				case "X-MICROSOFT-CDO-INTENDEDSTATUS":
					switch((string)$value){
						case "BUSY":
						case "FREE":
						case "TENTATIVE":
							$driver_event["free_busy"] = strtolower((string)$value);
							break;
						case "OOF":
							$driver_event["free_busy"] = "outofoffice";
							break;
					}
					break;
				case "STATUS":
					$driver_event["status"] = (string)$value;
					break;
				case "LOCATION":
					$driver_event["location"] = self::unescape($value);
					break;
				case "SUMMARY":
					$driver_event["title"] = self::unescape($value);
					break;
				case "DESCRIPTION":
					$driver_event["description"] = self::unescape($value);
					break;
				case "ORGANIZER":
					$email = (string)$value;
					if(strpos(strtolower($email), 'mailto:') === 0){$email = substr($email, 7);}
					$driver_event["attendees"][] = array(
									"role" => "ORGANIZER",
									"rsvp" => 1,
									"email" => $email,
									"name" => self::unescape($value['CN'])
									);
					break;
				case "ATTENDEE":
					$email = (string)$value;
					if(strpos(strtolower($email), 'mailto:') === 0){$email = substr($email, 7);}
					$driver_event["attendees"][] = array(
									"role" => (string)$value['ROLE'],
									"rsvp" => (string)$value['RSVP'] == "TRUE" ? 1 : 0,
									"email" => $email,
									"name" => self::unescape($value['CN']),
									"status" => (string)$value['PARTSTAT']
									);
					break;
				case "RRULE":
					$rec_array = array();
					foreach(explode(";", (string)$value) as $prop){
						list($prop_key, $prop_value) = explode("=", $prop);
						switch($prop_key){
							case "UNTIL":
								$rec_array[$prop_key] = new DateTime($prop_value);
								break;
							default:
								$rec_array[$prop_key] = $prop_value;
								break;
						}
					}
					$driver_event["recurrence"] = $rec_array;
					$driver_event["isexception"] = 0;
					break;
				case "RECURRENCE-ID":
					$driver_event["recurrence_id"] = gmdate("Ymd\THis\Z", $value->getDateTime()->format('U'));
					$driver_event["isexception"] = 0;
					$driver_event["id"] = $driver_event["id"]."/".gmdate("Ymd\THis\Z", $value->getDateTime()->format('U'));
					break;
				case "CLASS":
					$driver_event['sensitivity'] = strtolower((string)$value);
					break;
				case "PRIORITY":
					$driver_event['priority'] = (string)$value;
					break;
				case "VALARM":
					foreach($value->children as $valarm_child){
						if($valarm_child->name == "TRIGGER"){
							$valarm = array();
							$valarm['action'] = "DISPLAY";
							if(isset($valarm_child->parameters['RELATED'])){
								$valarm['related'] = strtolower((string)$valarm_child->parameters['RELATED']);
							}else{
								$valarm['related'] = "start";
							}
							$valarm['trigger'] = (string)$valarm_child;
							$driver_event["valarms"][] = $valarm;
						}
					}
					break;
				case "UID":
					$driver_event['uid'] = (string)$value;
					break;
				case "DTSTAMP":
				case "LAST-MODIFIED":
				case "CREATED":
				case "SEQUENCE":
				case "PRODID":
				case "X-MICROSOFT-CDO-APPT-SEQUENCE":
				case "X-MICROSOFT-CDO-OWNERAPPTID":
				case "X-MICROSOFT-CDO-OWNER-CRITICAL-CHANGE":
				case "X-MICROSOFT-CDO-ATTENDEE-CRITICAL-CHANGE":
				case "X-MICROSOFT-DISALLOW-COUNTER":
				case "X-MICROSOFT-CDO-BUSYSTATUS":
					break;
				default:
					rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => "unknown property: ".(string)$value->name.":".(string)$value), true, false);
			}
		}
		// If no end, default to same as start
		if(!isset($driver_event["end"])){$driver_event["end"] = $driver_event["start"];}
		// If no allday, guess based on start and end
		if($driver_event["allday"] == -1){
			$driver_event["allday"] = (strlen($driver_event["start"]) < 13 || strlen($driver_event["end"]) < 13) ? 1 : 0;
		}
		// If allday, substract one from end
		if($driver_event["allday"] == 1){
			$driver_event["end"] = intval($driver_event["end"]) -1;
		}
		// Convert start and end to date object
		$driver_event['start'] = new DateTime($driver_event["start"]);
		$driver_event['end'] = new DateTime($driver_event["end"]);

		// If no showas, default to busy
		if($driver_event["showas"] == -1){$driver_event["showas"] = 1;}
		
		return $driver_event;
	}
	
	public static function unescape($value){
		$string = (string)$value;
		$string = str_replace( '\\n', "\n", $string);
		$string = str_replace( '\\N', "\n", $string);
		$string = preg_replace( "/\\\\([,;:\"\\\\])/", '$1', $string);
		return $string;
	}
}