README: parser.php
	pod2text parser.php > $@

test: parser.php
	prove -e 'php' -m parser.php | tee $@
