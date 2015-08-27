## A simple API endpoint test program ##

### Installation ###

* `git clone git@github.com:dewen/apitest.git projectname`

### Usage ###

* Copy the config file from template: `cp playnotes.php.template playnotes.php`
* Edit `notes/*.yml` to create the play notes, 
* or you can edit in a PHP notes file (with template - notes/playnotes.php.template) and generate the yaml: `php notes/playnotes.php > notes/playnotes.yml`
* Play the notes: `php play.php`
