# Introduction

The purpose of this project is to regularly download all data housed by NEON relating to phenology and load that into the internal USA-NPN database. This is facilitated through a few pieces. First, the data is downloaded using the R library provided by NEON directly. There's a separate PHP script that extracts and transforms that data for the NPN's database. There's a bash script that coordinates these activities. The script has parameters for running once, as the initial load, but also should work in the long run for continued operations by allowing existing records to be updating and detecting new records to be inserted into the database.

# Install

This project requires NEON's R package for downloading and stacking files to function properly. Therefore make sure that the package is already installed in the R environment where this project will be running.

```
install.packages("neonUtilities")
```

Next, this project can be downloaded the standard way

```
git clone https://github.com/usa-npn/neon-import
```

Additionally this program depends on the PHPMailer package which is available separately on GitHub. It's not checked into the project directly so that it can be kept up-to-date more easily with the source repo. Really this should be setup with composer, but alas, it is not. From the main directory of the NEON import program:

```
git clone https://github.com/PHPMailer/PHPMailer
```

It's also helpful to setup a few directories that should be created when the script runs but might not if there are any permission issues. From the working directory:

```
mkdir data
mkdir R/data-files
mkdir R/stacked-files
```

You must also setup the config file.

```
mv config.ini.template config.ini
vi config.ini
```

Now populate the file with all the appropriate parameters. By default there's also a number of constants in the PHP script that may need to be changed.

```
define('DEBUG',1);
```
If set to 1 this won't actually commit any of the SQL transactions.

```
define('IS_INITIAL_RUN',1);
```
This will help speed things up if set to 1 during the initial run. But it's not appropriate to keep this on after the first load of data has successfully completed.


One major dependency for this project is having the NPN databsae available, and while it's safe to assume that most users of this file will be internal, there's changes to the database that need to be done to prep it for this script. Namely, this file must be executed against the database to create some the dependent entities:
https://github.com/usa-npn/dbvc/blob/master/usanpn2/updates/20190719-add-neon-dataset-id.sql

 



# Usage

There's a shell script that will run a script to use NEON's R library to download all their phenology records, move a few files and then kick off the main PHP ETL script.

```
sh load-neon.sh
```

That's all there is to it. If there's a problem there's two log files which can be checked later, output.txt and run.txt.


# Yearly Neon release

Early each year neon makes a new release where we purge all of our records and rerun a full import.
1. run all of the queries in neon-purge.sql
2. in a tmux session on npn-util run NEON-data-script.R with startdate="2012-01-01", enddate="{current-date}", I have recently been having to run this locally on my laptop and then scping the files to npn-util as the files don't always save on the ec2 instance even when using sudo. This doesn't take too long with a fast connection.
3. pause datawarehouse scripts 
4. run neon-import.php, this will take 2 to 3 days.
5. turn datawarehousing back on and verify the neon records make it to the cached tables

# Monthly provisional imports

A cron job will run each month on npn-util that is the same as the yearly release except no purge using a sliding window of one month for the startdate and enddate.

# Supporting docs
[A few exchanges between Ellen, Katie, Jeff, and Claire](https://docs.google.com/document/d/15TbM7Dd2uD-mzTLdXicoQB3voASqgErW34bMS8ypD9Y/edit?tab=t.0)

