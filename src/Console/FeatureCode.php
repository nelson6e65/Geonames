<?php

namespace MichaelDrennen\Geonames\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MichaelDrennen\Geonames\Models\GeoSetting;
use MichaelDrennen\Geonames\Models\Log;

/**
 * Class FeatureCode
 * @package MichaelDrennen\Geonames\Console
 */
class FeatureCode extends Command {

    use GeonamesConsoleTrait;

    /**
     * @var string  The name and signature of the console command.
     */
    protected $signature = 'geonames:feature-code';

    /**
     * @var string  The console command description.
     */
    protected $description = "Download and insert the feature code files from geonames. Every language. They're only ~600 rows each.";


    /**
     * The name of our feature codes table in our database. Using constants here, so I don't need
     * to worry about typos in my code. My IDE will warn me if I'm sloppy.
     */
    const TABLE = 'geonames_feature_codes';

    /**
     * The name of our temporary/working table in our database.
     */
    const TABLE_WORKING = 'geonames_feature_codes_working';


    /**
     * Create a new command instance.
     */
    public function __construct() {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle() {
        $this->startTimer();

        GeoSetting::init();

        // Get all of the feature code lines from the geonames.org download page.
        $featureCodeFileDownloadLinks = $this->getFeatureCodeFileDownloadLinks();

        // Download each of the files that we found.
        $localPathsToFeatureCodeFiles = self::downloadFiles($this, $featureCodeFileDownloadLinks);

        // Run each of those files through LOAD DATA INFILE.
        Schema::dropIfExists(self::TABLE_WORKING);
        DB::statement('CREATE TABLE ' . self::TABLE_WORKING . ' LIKE ' . self::TABLE . ';');

        // Now that we have all of the feature code files stored locally, we need to prepare
        // the data to be inserted into our database. Convert each tab delimited row into a php
        // array, and add the language code from the file name as another field for each row.
        // Also, we do a check to see if the row holds valid data. See the comments for
        // isValidRow() for details.
        $validRows = $this->getValidRowsFromFiles($localPathsToFeatureCodeFiles);

        // Now that we have our rows, let's insert them into our working table.
        $allRowsInserted = $this->insertValidRows($validRows);

        if ($allRowsInserted === true) {
            Schema::drop(self::TABLE);
            Schema::rename(self::TABLE_WORKING, self::TABLE);
            $this->info( self::TABLE . " table was truncated and refilled in " . $this->getRunTime() . " seconds." );
        } else {
            Log::error( '', "Failed to insert all of the " . self::TABLE . " rows.", 'database' );
        }
    }


    /**
     * There are feature code files on geonames.org for a few different languages. The file names
     * all start with 'featureCodes_', so we get all of the links from the page, and only return
     * ones that start with that string.
     * @return array A list of all of the featureCode files from the geonames.org site.
     */
    protected function getFeatureCodeFileDownloadLinks(): array {
        $links = $this->getAllLinksOnDownloadPage();

        $featureCodeFileDownloadLinks = [];
        foreach ($links as $link) {
            $string = 'featureCodes_';
            $length = strlen($string);
            if (substr($link, 0, $length) === $string) {
                $featureCodeFileDownloadLinks[] = self::$url . $link;
            }
        }

        return $featureCodeFileDownloadLinks;
    }

    /**
     * Each feature code file downloaded from geonames.org has a language code as part of the file name.
     * We insert the feature code rows from all of the files into the same table.
     * We manually add a language_code field to the table, and use the code from the file name
     * to populate it.
     * @param string $absoluteLocalFilePath The absolute local file path to the feature code file.
     * @return string   The two character language code for the feature code file.
     */
    protected function getLanguageCodeFromFileName(string $absoluteLocalFilePath): string {
        $basename = basename($absoluteLocalFilePath, '.txt');
        $nameParts = explode('_', $basename);
        $languageCode = $nameParts[1];

        return $languageCode;
    }




    /**
     * The geonames.org featureCodes file has an ending row:
     * null    not available
     * I'm not sure if it will always be there. (If it is, then I could just pop it off the end.)
     * Since I can't necessarily count on that, then let's do a more robust check to
     * make sure the row is valid.
     * Basically make sure that whatever data is in that row can be inserted into the database.
     * A valid row will look like this:
     * H.CNL    canal    an artificial watercourse
     * ...and by the time we run this function, we may have already appended the language code
     * to the end of the row. But in this function, we only check the value in the first field.
     */
    protected function isValidRow(array $row) {
        $classAndCode = explode('.', $row[0]);
        if (count($classAndCode) != 2) {
            return false;
        }
        if (empty($classAndCode[0]) || empty($classAndCode[1])) {
            return false;
        }

        return true;
    }

    /**
     * The parsed data from the tab delimited feature code file can not be passed directly into an
     * Eloquent create() call. So we massage the data into an associative array that can be.
     * @param array $row A numerically indexed array of feature code data.
     * @return array    An associative array that we can pass right into an Eloquent create() call.
     */
    protected function makeRowInsertable(array $row): array {
        list($id, $name, $description, $language_code) = $row;
        list($feature_class, $feature_code) = explode('.', $id);

        return ['language_code' => $language_code,
                'feature_class' => $feature_class,
                'feature_code'  => $feature_code,
                'name'          => $name,
                'description'   => $description,
                'created_at'    => Carbon::now(),
                'updated_at'    => Carbon::now(),];
    }

    /**
     * @param array $localPathsToFeatureCodeFiles
     * @return array
     */
    protected function getValidRowsFromFiles(array $localPathsToFeatureCodeFiles): array {
        $validRows = [];
        foreach ($localPathsToFeatureCodeFiles as $i => $file) {
            $languageCode = $this->getLanguageCodeFromFileName($file);
            $dataRows = self::csvFileToArray($file);
            foreach ($dataRows as $j => $row) {
                if ($this->isValidRow($row)) {
                    $dataRows[ $j ][] = $languageCode;
                    $validRows[] = $dataRows[ $j ];
                }
            }
        }

        return $validRows;
    }

    /**
     * Insert all of the data into our database. We insert these rows into a 'working' table, not the
     * live table. We do this so users can safely update the geonames_feature_codes table on a production box
     * without any significant downtime.
     * @param array $validRows An associative array of data from the feature code files from geonames.org
     * @return bool Returns true if all of the rows were inserted. False otherwise.
     */
    protected function insertValidRows(array $validRows): bool {
        $numRowsInserted = 0;
        $numRowsNotInserted = 0;
        $numRowsToBeInserted = count($validRows);

        $this->disableKeys( self::TABLE_WORKING );

        // Progress bar for console display.
        $bar = $this->output->createProgressBar( $numRowsToBeInserted );
        $bar->setFormat( "Inserting %message% %current%/%max% [%bar%] %percent:3s%%\n" );
        $bar->setMessage( 'feature codes' );
        $bar->advance();


        foreach ($validRows as $rowNumber => $row) {

            $insertResult = DB::table( self::TABLE_WORKING )->insert( $this->makeRowInsertable( $row ) );

            if ($insertResult === true) {
                $numRowsInserted++;
            } else {
                $numRowsNotInserted++;
                $this->error("\nRow " . $rowNumber . " of " . $numRowsToBeInserted . " was NOT inserted.");
            }
            $bar->advance();
        }

        $this->enableKeys( self::TABLE_WORKING );

        if ($numRowsInserted != $numRowsToBeInserted) {
            return false;
        }
        return true;
    }


}
