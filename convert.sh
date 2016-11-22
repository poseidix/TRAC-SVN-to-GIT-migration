#!/bin/bash

# display help message
if [ "$1" == "-h" ]; then
	echo "Usage: $0 [repositoryName]"
	exit 0
fi

# Repository-path
basePath=/path/to/your/repos

# loop over all repositories found
for repoPath in $(find $basePath -type d -name \*.git -prune) ; do
	repo=$(basename "$repoPath")

	# if argument given, only execute php for the given repository
	if [ -n "$1" ] && [ $repo != $1 ]
	then
		continue
	fi

	# Config file found, this seems to be a repository
	if [ -f $repoPath/config ]; then
		echo "* Converting Trac $repo start"

		# Creates revision-history
		git --git-dir=$repoPath rev-list --all --pretty=medium --grep='; revision=' > revlist.txt

		# Now extract the git hash and the svn ID
		grep 'revision=\d*' revlist.txt | sed -e 's/.*svn path=.*; revision=\(\d*\)/\1/' > svn.txt
		grep '^commit [0-9a-f]*' revlist.txt | sed -e 's/commit //' > git.txt

		# Join them pair-wise and write the lookup table
		paste svn.txt git.txt | sort -n > lookupTable.txt

		# Migrate
		php convertTracTickets.php $repo

		# Clean up
		rm svn.txt git.txt revlist.txt lookupTable.txt

		echo "* Converting Trac $repo end"
		echo ""
	fi
done
