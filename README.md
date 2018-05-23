# calendar_caldavsso
CalDAV driver for kolab/calendar with SSO option

You need to manually install this. Copy the files to <roundcube_install>/plugins/calendar/drivers/caldavsso.

Configure the default calendar in config_inc.php.

Configure the calendar to use the driver by setting: $config['calendar_driver'] = "caldavsso";

Enable the calendar plugin.

TODO:
 - Show alarms
 - Reset attendees status when updating event start or end
 - Search in event description
 
