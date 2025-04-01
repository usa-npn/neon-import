<!-- run the following to purge all NEON data from the database before importing a new yearly release -->
use usanpn2;
DELETE FROM Dataset_Observation WHERE Dataset_ID = 16; #4445013 deleted instant #prod: 3742316 #prod2025: 5854445
DELETE FROM Observation WHERE Observer_ID < -2;        #4445013 25min #prod: 3824260 #prod2025: 5854445
DELETE FROM Observation_Group WHERE Observer_ID < -2;  #18563, instant #15298 #prod2025: 24630
SELECT * FROM Observation WHERE Individual_ID IN (SELECT Individual_ID FROM Station_Species_Individual WHERE Individual_UserStr LIKE "NEON%");
SELECT * FROM Person WHERE Person_ID = 2970;
DELETE FROM Observation WHERE Individual_ID IN (SELECT Individual_ID FROM Station_Species_Individual WHERE Individual_UserStr LIKE "NEON%"); #27 rows
DELETE FROM Station_Species_Individual WHERE Individual_UserStr LIKE "NEON%"; #9948, instant prod: 8681 #prod2025: 10055
DELETE FROM Network_Station ns WHERE Network_ID = 77; #95 rows prod 95 rows #prod2025: 92
SELECT * FROM Station_Species_Individual ssi WHERE Station_ID IN (SELECT Station_ID FROM Station WHERE Observer_ID < -2);
DELETE FROM Station_Species_Individual ssi WHERE Station_ID IN (SELECT Station_ID FROM Station WHERE Observer_ID < -2); #1 row
DELETE FROM Station WHERE Observer_ID < -2; #94 rows #prod2025: 92
DELETE FROM Submission WHERE Create_Person_ID < -2; #18608 #prod2025:24689
DELETE FROM Person WHERE Person_ID < -2 AND Person_ID != -5; #1903 prod 1721 #prod2025: 2093

<!-- checks -->
SELECT * FROM Dataset_Observation WHERE Dataset_ID = 16;
SELECT count(*) FROM Observation WHERE Observer_ID < -2; #5840509
SELECT count(*) FROM Observation; #39871504
SELECT * FROM Observation_Group WHERE Observer_ID < -2;
SELECT * FROM Station_Species_Individual ssi WHERE Individual_UserStr LIKE "NEON%";
SELECT * FROM Network n WHERE Network_ID = 77;
SELECT * FROM Network_Station ns WHERE Network_ID = 77;
SELECT * FROM Station WHERE Observer_ID < -2;
SELECT * FROM Person WHERE Person_ID < -2 AND Person_ID != -5;
SELECT * FROM Submission WHERE Session_ID = -1;
SELECT * FROM Cached_Observation co ORDER BY Observation_Date DESC LIMIT 100;
SELECT COUNT(*) FROM Cached_Observation co;
SELECT COUNT(*) FROM Observation o;