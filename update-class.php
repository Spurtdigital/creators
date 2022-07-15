<?php

namespace Kevin;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

if( ! class_exists( 'UpdateChecker' ) ) {

	class UpdateChecker{

        //Setup vars
		public $plugin_name;
		public $plugin_slug;
		public $version;
		public $transient_cachekey;
		public $cache_allowed;
        public $github_user;
        public $github_repo;
        public $destination;

        /**
         * Construct function to setup all update related variables
         */
		public function __construct() {

			//Setters:
			$this->plugin_name = 'Creators';
			$this->github_user = 'Spurtdigital';
			$this->github_repo = 'creators';
			$this->version = '0.5.3';

            //Set the need info for the transient
			$this->plugin_slug = plugin_basename( __DIR__ );
			$this->transient_cachekey = 'spurtdigital';
			$this->cache_allowed = false;
			$this->destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/package';

            //Filter on three items
			\add_filter( 'wp', array( $this, 'test_download' ), 20, 3 );
			\add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
			\add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update' ) );
			\add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );

		}

        /**
         * Request function, here we do a request to get info from the server
         *
         * @return void
         */
		public function request(){

            //Get the transient info
			$remote = get_transient( $this->transient_cachekey );

            //If theres none or we are not allowed to cache get new
			if( false === $remote || ! $this->cache_allowed ) {

                //Curl call to github
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://raw.githubusercontent.com/'.$this->github_user.'/'.$this->github_repo.'/master/README.md');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $data = curl_exec($ch);
                curl_close($ch);

                //Do a preg match to get the version from the readme
				preg_match( '#^\s*`*~Current Version\:\s*([^~]*)~#im', $data, $__version );

                //Check for an entry in the version array
				if ( isset( $__version[1] ) ) {

					//Include wp version 
					require ABSPATH . WPINC . '/version.php';

					//Now we only need to download & zip the git repo... for the donwload url
					$download_url = $this->download_repo();

					//Get string position of 'wp-content'
					$wp_content_pos = strpos( $download_url, 'wp-content' );

					//Cut off the download url starting from wp_content_post
					$download_url = get_site_url() . '/' . substr( $download_url, $wp_content_pos );

                    //We have to setup an entirely new plugin object :c
					$res = new \stdClass();
					$res->name = $this->plugin_name;
					$res->slug = $this->plugin_slug;
					$res->version = $__version[1];
					$res->tested = $wp_version;
					$res->requires = $wp_version;
					$res->author = 'Kevin Brinkman';
					$res->author_profile = 'www.kevinbrinkman.nl';
					$res->download_link = $download_url; 
					$res->trunk = $download_url; 
					$res->requires_php = phpversion();
					$res->last_updated = date('Y-m-d');

                }

				$remote = $res;

                //Save in cache
				\set_transient( $this->transient_cachekey, $remote, DAY_IN_SECONDS );
				
			}

            //And return obj.
			return $remote;

		}

		/**
		 * Fuction to download the repo wiuth curl
		 *
		 * @return void
		 */
		function download_repo() {

			//Do some terminal/ssh commands to navigate to right folder
			$dir = getcwd();

			if(!is_dir($this->destination)) return false;

			chdir($this->destination);
			$rootpath = getcwd();
			
			try {

				//Clone the git repo
				$result = exec('git clone https://github.com/' . $this->github_user . '/' . $this->github_repo . '.git');

				// Initialize empty "delete list"
				$filesToDelete = array();

				//Check if succesfully downloaded
				if (is_dir($rootpath)) {

					//Create ZIP
					$zip = new ZipArchive();
					$zip->open(\wp_upload_dir()['basedir'] . '/' . $this->plugin_slug . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

					//We have to get the real folder inside...
					$directories = glob($rootpath . '/*' , GLOB_ONLYDIR);
					$recursivepath = $directories[0];

					// Create recursive directory iterator
					$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($recursivepath),RecursiveIteratorIterator::LEAVES_ONLY);
					
					foreach ($files as $name => $file) {
						// Skip directories (they would be added automatically)
						if (!$file->isDir()) {
							// Get real and relative path for current file
							$filePath = $file->getRealPath();
							$relativePath = substr($filePath, strlen($rootpath) + 1);
		
							// Add current file to archive
							$zip->addFile($filePath, $relativePath);
		
							// Add current file to "delete list"
							$filesToDelete[] = $filePath;
						}
					}

					// Zip archive will be created only after closing object
					$zip->close();

					//At the end we can remove the original folder
					$this->remove_package_folder($rootpath);
				}

				//Return ZIP location
				return \wp_upload_dir()['basedir'] . '/' . $this->plugin_slug . '.zip';

			} catch (Exception $e) {

				echo 'Caught exception: ',  $e->getMessage(), "\n";
			}
		}

		/**
		 * Remove the entire package folder
		 *
		 * @param [type] $rootpath
		 * @return void
		 */
		private function remove_package_folder($rootpath)
		{
			//Set repo path
			$dir = $rootpath . '/' . $this->github_repo;
	
			//Check if it's a dir if so remove
			if (is_dir($dir)) {
				//Setup recursives
				$it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
				$files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

				//Loop over files to remove
				foreach ($files as $file) {
					if ($file->isDir()) {
						rmdir($file->getRealPath());
					} else {
						unlink($file->getRealPath());
					}
				}
				rmdir($dir);
			}
		}

        /**
         * Function info() collects array with newest or saved plugin information to add to update transients
         *
         * @param [type] $res
         * @param [type] $action
         * @param [type] $args
         * @return void
         */
		function info( $res, $action, $args ) {

			//If we don't ask for plugin info, bail
			if( 'plugin_information' !== $action ) return $res;

			//Also do nothing if it's not our plugin
			if( $this->plugin_slug !== $args->slug ) return $res;

			//Get the update info from this plugin
			$remote = $this->request();

			if(!$remote) return $res;

            //Set plugin object with info from request info
			$res = new \stdClass();
			$res->name = $remote->name;
			$res->slug = $remote->slug;
			$res->version = $remote->version;
			$res->tested = $remote->tested;
			$res->requires = $remote->requires;
			$res->author = $remote->author;
			$res->author_profile = $remote->author_profile;
			$res->download_link = $remote->download_url;
			$res->trunk = $remote->download_url;
			$res->requires_php = $remote->requires_php;
			$res->last_updated = $remote->last_updated;

            //Return simple array
			return $res;

		}

        /**
         * Function update() adds the update info to the update transients and enables the update bloop in wp admin
         *
         * @param [type] $transient
         * @return void
         */
		public function update( $transient ) {

            //If the checked var in transient is empty, return the cached version
			if (empty($transient->checked) ) return $transient;

            //Else get all info from a remote request...
			$remote = $this->request();
			
			if(
				$remote
				&& version_compare( $this->version, $remote->version, '<' )
				&& version_compare( $remote->requires, get_bloginfo( 'version' ), '<=' )
				&& version_compare( $remote->requires_php, PHP_VERSION, '<=' )
			) {
				$res = new stdClass();
				$res->slug = $this->plugin_slug;
				$res->plugin = $this->plugin_slug . "/" . $this->github_repo . ".php";
				$res->new_version = $remote->version;
				$res->tested = $remote->tested;
				$res->package = $remote->download_link;

				$transient->response[ $res->plugin ] = $res;

			}

			return $transient;

		}

		public function purge( $upgrader, $options ){

			if (
				$this->cache_allowed
				&& 'update' === $options['action']
				&& 'plugin' === $options[ 'type' ]
			) {
				// just clean the cache when new plugin version is installed
				delete_transient( $this->transient_cachekey );
			}

		}


	}

	new UpdateChecker();

}