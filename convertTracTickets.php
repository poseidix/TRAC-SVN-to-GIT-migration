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
$nrHashCharacters = 8;

/* END CONFIGURATION */

/**
 * Converts a text with references to an SVN revision [1234] into the corresponding GIT revision
 *
 * @param text Text to convert
 * @param lookupTable Conversion table from SVN ID to Git ID
 * @returns True if conversions have been made
 */
function convertSVNIDToGitID(&$text, $lookupTable, $nrHashCharacters)
{		
	// Extract references to SVN revisions [####]
	$pattern = '/\[([0-9]+)\]/';
	
	if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) > 0)
	{		
		foreach($matches as $match)
		{		
			$svnID = $match[1];
			if (!isSet($lookupTable[$svnID]))
			{
				echo "Warning: unknown GIT hash for SVN revision $svnID\n";
				continue;
			}
			$gitID = substr($lookupTable[$svnID], 0, $nrHashCharacters);
			
			$text = str_replace('[' . $svnID . ']', '[' . $gitID . '] (SVN r' . $svnID . ')', $text);
		}
		
		return true;
	}
	
	return false;
}

echo "Creating SVN -> GIT conversion table table...\n";

// Create the lookup table
$lines = file($pathLookupTable);
foreach ($lines as $line)
{	
	if (empty($line)) continue;	
	list ($svnID, $gitID) = explode("\t", trim($line));	
	$lookupTable[$svnID] = $gitID;
}

// Connect to the TRAC database
$db = new SQLite3($pathDB);

echo "Converting table 'ticket_change'...\n";

// Convert table 'ticket_change'
$result = $db->query('SELECT * FROM ticket_change'); 

$i = 1;
while ($row = $result->fetchArray())
{			
	$i++;
	$oldValue = $db->escapeString($row['oldvalue']);
	$newValue = $db->escapeString($row['newvalue']);
	
	// Only update when there is something to be changed, since SQLite isn't the fastest beast around
	if (convertSVNIDToGitID($oldValue, $lookupTable, $nrHashCharacters) || convertSVNIDToGitID($newValue, $lookupTable, $nrHashCharacters))
	{	
		$query = "UPDATE ticket_change SET oldvalue='$oldValue', newvalue='$newValue' WHERE ticket = '${row['ticket']}' AND time = '${row['time']}' AND author='${row['author']}' AND field='${row['field']}'";
		if (!$db->exec($query))
		{
			echo "Query failed: " . $query . "\n";
		}		
		
		echo "Updated ticket_change $i\n";
	}
}

echo "Converting table 'ticket'...\n";

// Convert table 'ticket'

$i = 1;

$result = $db->query('SELECT * FROM ticket');
while ($row = $result->fetchArray())
{
	$description = $db->escapeString($row['description']);
	if (convertSVNIDToGitID($description, $lookupTable, $nrHashCharacters))
	{	
		$query = "UPDATE ticket SET description='$description' WHERE id = " . $row['id'];
		$db->exec($query);
		
		echo "Updated ticket $i\n";
	}
}

// Done :)
echo "Done!\n";
?>
