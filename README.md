XIRC2
=====

XIRC2 IRC Bot framework, used for Fortuna (badniknet/#fortune) and Kikyo (esper/#srb2fun)

To run from the command line, use `php x.php`.


Configuration notes:
* All configuration files must reside in "/settings/".
* "example.ini" is an example configuration file that hopefully should give you enough info to set up the bot normally.
* The default configuration file is "options.txt", which doesn't exist normally.
* To use a different configuration file, specify the file name in the command line arguments: `php x.php fortune.txt`


Log notes:
* If `logfolder` is true in your configuration file, you must create the folder that `logprefix` specifies yourself. It won't create it for you, and you'll get a lot of fopen/fwrite errors if you don't.
* Otherwise, all log files will be prefixed by whatever the value of `logprefix` is.
* All console text is logged to "console.txt".
* Currently, all text, including received lines, sent lines, and console text, is written into a daily log file in the form "(year)(month)(day).txt"
