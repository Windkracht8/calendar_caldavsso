# caldavsso
CalDAV driver for kolab/calendar with SSO option

You need to manually install this. Copy the files to <roundcube_install>/plugins/calendar/drivers/caldavsso.

Configure the calendar to use the driver by setting: $config['calendar_driver'] = "caldavsso";

TODO:
 - Show alarms
 - Updating one occurence of an recurring event
 - Timezones can be better
 - Store passwords encrypted
 