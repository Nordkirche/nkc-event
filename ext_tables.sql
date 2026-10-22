#
# Date ranges for the date filter of the search form.
# Standard columns (uid, pid, tstamp, crdate, deleted, hidden, starttime, endtime) are created from TCA.
#
CREATE TABLE tx_nkcevent_domain_model_filterdate (
	name varchar(255) DEFAULT '' NOT NULL,
	be_name varchar(255) DEFAULT '' NOT NULL,
	date_from int(11) DEFAULT '0' NOT NULL,
	date_to int(11) DEFAULT '0' NOT NULL
);
