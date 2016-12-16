<?php

/**
 * This script converts the commit references from SVN IDs to GIT IDs, i.e. changing in all tickets
 * [1234] to [a42v2e3] or whatever the corresponding GIT hash is
 *
 * It needs a SVN ID -> GIT ID lookup table file called lookupTable.txt to match IDs.
 *
 * Execute it with php.exe convertTracTickets.php
 *
 * Needs the sqlite3 extension enabled to access the TRAC database.
 **/
error_reporting(E_ALL);

/* CONFIGURATION */

// Path to trac DB
$pathDB = "/path/to/your/trac.db";

// Path to lookup table (SVN revision number to GIT revion hash)
$pathLookupTable = "lookupTable.txt";

// Number of characters for the changeset hash. This has to be 4 <= nr <= 40
$nrHashCharacters = 40;

// Name of Repository
$repositoryName = $argv[1];

// Ignored SVN Ids
$blockedSVNIds = [1, 0];

echo "* Migrating " . $repositoryName . "\n";

/* END CONFIGURATION */

echo "* Creating SVN -> GIT conversion table ...\n";

// Statistics
$ticketCounter = new TicketCounter();

// Create the lookup table
$lines = file($pathLookupTable);
foreach ($lines as $line) {
	if (empty($line)) continue;
	list ($svnID, $gitID) = explode("\t", trim($line));
	$lookupTable[$svnID] = $gitID;
}

// Connect to the TRAC database
$db = new SQLite3($pathDB);
$db->busyTimeout(5000);

// WAL-Mode (https://www.sqlite.org/wal.html)
if (!$db->exec('PRAGMA journal_mode = wal;')) {
	echo "Using default database-mode!\n";
}

// Get last ticket
$result = $db->query('SELECT MAX(id) FROM ticket');
$row = $result->fetchArray();
$maxTickets = $row[0];
$result->finalize();

// Convert table 'ticket_change'
echo "* Converting table 'ticket_change'...\n";
if (!$db->query('BEGIN TRANSACTION')) {
	echo "Unable to start transaction!\n";
}
$result = $db->query('SELECT * FROM ticket_change ORDER by ticket');
while ($row = $result->fetchArray()) {
	$oldValue = $db->escapeString($row['oldvalue']);
	$newValue = $db->escapeString($row['newvalue']);
	$oldValueOld = $oldValue;
	$newValueOld = $newValue;

	// Only update when there is something to be changed, since SQLite isn't the fastest beast around
	if (convertSVNIDToGitID($oldValue, $row['ticket']) || convertSVNIDToGitID($newValue, $row['ticket'])) {
		#echo "Updating ticket_change ${row['ticket']} of $maxTickets\n";
		$query = "UPDATE ticket_change SET oldvalue='$oldValue', newvalue='$newValue' WHERE ticket = '${row['ticket']}' AND time = '${row['time']}' AND author='${row['author']}' AND field='${row['field']}'";
		if (!$db->exec($query)) {
			echo "  Query failed: " . $query . "\n";
		}
	}
}
$result->finalize();
if (!$db->query('END TRANSACTION')) {
	echo "Unable to end transaction!\n";
}

// Convert table 'ticket'
echo "* Converting table 'ticket'...\n";
if (!$db->query('BEGIN TRANSACTION')) {
	echo "Unable to start transaction!\n";
}

$result = $db->query('SELECT * FROM ticket ORDER by id');
while ($row = $result->fetchArray()) {
	$description = $db->escapeString($row['description']);
	if (convertSVNIDToGitID($description, $row['id'])) {
		#echo "Updating ticket ${row['id']} of $maxTickets\n";
		$query = "UPDATE ticket SET description='$description' WHERE id = ${row['id']}";
		if (!$db->exec($query)) {
			echo "  Query failed: " . $query . "\n";
		}
	}
}
$result->finalize();
if (!$db->query('END TRANSACTION')) {
	echo "Unable to end transaction!\n";
}

// Disconnect database
$db->close();

// Statistics
#$ticketCounter->output(10);

/*
 * ticket-counter
 */

class TicketCounter
{
	private $tickets = [];

	public function add($svnId, $ticket)
	{
		if(!isset($this->tickets[$svnId]))
		{
			$this->tickets[$svnId] = ["Count" => 0, "Svn" => $svnId, "Tickets" => []];
		}

		$this->tickets[$svnId]["Tickets"][] = $ticket;
		$this->tickets[$svnId]["Count"]++;
	}

	public function output($max)
	{
		$tickets = $this->tickets;

		usort($tickets, function($a, $b)
		{
			if($a["Count"] > $b["Count"])
			{
				return -1;
			} else if($a["Count"] < $b["Count"])
			{
				return 1;
			}
			return 0;
		});

		$_tickets = array_slice($tickets, 0, $max);

		echo "\n\nTop ".$max." SvnID\n";
		foreach($_tickets as $ticket)
		{
			echo "SvnID ".$ticket["Svn"]." -> ".($ticket["Count"])." (Tickets: ".implode(", ", $ticket["Tickets"]).")\n";
		}
	}
}

function replace($pattern, &$text, $before, $after, $flag, $ticketNr) {
	global $nrHashCharacters, $lookupTable, $repositoryName, $ticketCounter, $blockedSVNIds;


	
	if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) > 0) {
		#if(isset($matches[1])) {
			foreach ($matches as $match) {
				$svnID = $match[1];

				if (in_array($svnID, $blockedSVNIds)) {
					continue;
				}

				$ticketCounter->add($svnID, $ticketNr);

				if (!array_key_exists($svnID, $lookupTable)) {
					#echo "Warning: Unknown GIT hash for SVN revision $svnID (Ticket $ticketNr)\n";
					continue;
				}

				$gitID = substr($lookupTable[ $svnID ], 0, $nrHashCharacters);
				$textOld = $text;
				$text = str_replace('#!CommitTicketReference repository=""', '#!CommitTicketReference repository="' . $repositoryName . '"', $text);
				if ($flag) {
					$text = preg_replace($pattern, $before . $gitID . '/' . $repositoryName . $after, $text);
				} else {
					$text = preg_replace($pattern, $before . $gitID . $after, $text);
				}
			}
		#}

		return true;
	}
	
	return false;
}

/**
 * Converts a text with references to an SVN revision [1234] into the corresponding GIT revision
 *
 * @param text Text to convert
 * @param lookupTable Conversion table from SVN ID to Git ID
 * @returns True if conversions have been made
 */
function convertSVNIDToGitID(&$text, $ticketNr) {
	global $lookupTable, $nrHashCharacters;
	
	if (empty($text)) {
		return false;
	}
    
	// Extract references to SVN revisions [changeset:"####"], [changeset:####], revision="####", [####], r####
	$return = false;
	#$pattern = '/[\s(]\[([0-9]+)\](?!( =>)|( -->)|("))/';
	$pattern = '/(?<!(SQLSTATE\[(.){5}\] ))[\s(](\[\d+\])(?!( =>)|( -->)|(\"))/';
	$return |= replace($pattern, $text,'[',']',true,$ticketNr);
	$pattern = '/[\s\(]r([0-9]+)[\s\)]/';
	$return |= replace($pattern, $text,'r','',true,$ticketNr);
	$pattern = '/revision=\"([0-9]+)\"/';
	$return |= replace($pattern, $text,'revision="','"',false,$ticketNr);
	$pattern = '/\[changeset:"?([0-9]+)"?\]/';
	$return |= replace($pattern, $text,'[changeset:',']',true,$ticketNr);

	return $return;
}

?>
