# calendar_caldavsso
CalDAV driver for kolab/calendar with SSO option

You need to manually install this. Copy the files to <roundcube_install>/plugins/calendar/drivers/caldavsso.

Configure the default calendar in config_inc.php.

Configure the calendar to use the driver by setting: $config['calendar_driver'] = "caldavsso";

Enable the calendar plugin.

TODO:
 - Alarms
 - Search in event description
 - Better support for recurring meetings 
