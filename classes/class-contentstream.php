<?php
/**
 * Manages connection to the Content Stream service and provides access to
 * SOAP methods for listing and downloading content from the queue.
 *
 * Class ContentStream
 */
class ContentStream {


	/**
	 * Content Stream account username.
	 *
	 * @var string
	 */
	public $username;

	/**
	 * Content Stream account password.
	 *
	 * @var string
	 */
	public $password;

	/**
	 * Content Stream feed ID number.
	 *
	 * @var string
	 */
	public $feed_id;

	/**
	 * URL of the Content Stream API endpoint.
	 *
	 * @var string
	 */
	public $api_url;

	/**
	 * Max number of results to return in a request to the Content Stream service.
	 *
	 * @var int
	 */
	private $max_results;

	/**
	 * Offset used to move to different articles in the queue.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Defines the class maps (relationship between the message portion of wsdl and php class).
	 *
	 * @var array
	 */
	private $class_map;

	/**
	 * ContentStream constructor.
	 *
	 * @param string $api_url URL of the Content Stream API endpoint.
	 * @param string $username Content Stream account username.
	 * @param string $password Content Stream account password.
	 * @param int    $feed_id  Content Stream feed ID number.
	 */
	public function __construct( $api_url, $username, $password, $feed_id ) {

		require_once CSFR_CLASSES_DIR . 'soap-classes.php';

		$this->api_url     = $api_url;
		$this->username    = $username;
		$this->password    = $password;
		$this->feed_id     = $feed_id;
		$this->max_results = 10;
		$this->offset      = 0;

		$this->class_map = array(
			'getContentListRequest'   => 'GetContentListRequest',
			'getContentListResponse'  => 'GetContentListResponse',
			'getArticleRequest'       => 'GetArticleRequest',
			'getArticleResponse'      => 'GetArticleResponse',
			'deleteFromQueueRequest'  => 'DeleteFromQueueRequest',
			'deleteFromQueueResponse' => 'DeleteFromQueueResponse',
		);
	}

	/**
	 * Connects to ContentStream and retrieves a list of available content
	 *
	 * @return getContentListResponse
	 */
	function get_content_list() {

		$response = null;
		try {

			// 'SOAP_SINGLE_ELEMENT_ARRAYS' is to turn off a SoapClient 'feature' that auto converts single element arrays to the value of the item.
			$client = new SoapClient( $this->api_url, array(
				'classmap' => $this->class_map,
				'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
			) );

			$input                            = new GetContentListRequest();
			$input->username                  = $this->username;
			$input->password                  = $this->password;
			$input->feedDefinitionId          = $this->feed_id;
			$input->maxNumberResultsRequested = $this->max_results;
			$input->offset                    = $this->offset;

			$response = $client->getContentList( $input );

		} catch ( Exception $e ) {
			error_log( 'Error downloading articles: ' . $e->getMessage() );
		}

		return $response;
	}

	/**
	 * Downloads the content specified in $content_list, returning the number of articles successfully transferred.
	 *
	 * @param getContentListResponse $content_list Response object from GetContentList request.
	 * @param string                 $upload_dir Directory where downloaded files will go.
	 * @param string                 $local_image_path Directory for downloaded images.
	 * @param bool                   $delete_downloaded Flag to indicate whether to delete the downloaded item from the queue.
	 *
	 * @return int
	 */
	function download_content( $content_list, $upload_dir, $local_image_path, $delete_downloaded = false ) {
		$article_count = 0;

		try {

			// 'SOAP_SINGLE_ELEMENT_ARRAYS' is to turn off a SoapClient 'feature' that auto converts single element arrays to the value of the item.
			$client   = new SoapClient( $this->api_url, array(
				'classmap' => $this->class_map,
				'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
			) );
			$arr_uids = $content_list->arrUid;

			if ( is_array( $arr_uids ) ) {

				foreach ( $arr_uids as $uid ) {

					$input                   = new GetArticleRequest();
					$input->username         = $this->username;
					$input->password         = $this->password;
					$input->uid              = $uid;
					$input->feedDefinitionId = $this->feed_id;
					$response_get_article    = $client->getArticle( $input );

					if ( 1 === $response_get_article->errorOccurred ) {
						error_log( 'Error in getArticle: ' . $response_get_article->errorDescription );
					} else {

						$xml_file_name = basename( $response_get_article->articleXMLURL );

						$ch = curl_init();
						curl_setopt( $ch, CURLOPT_URL, $response_get_article->articleXMLURL );
						curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
						curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
						curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
						$output = curl_exec( $ch );

						$fp = fopen( $upload_dir . '/' . $xml_file_name, 'w' );
						fwrite( $fp, $output );
						fclose( $fp );
						curl_close( $ch );

						// This is an array of image assets contained within the article.
						$article_asset_url = $response_get_article->arrArticleAssetURL;

						// Save each image and display file url.
						$article_asset_count = count( $article_asset_url );
						for ( $image_counter = 0; $image_counter < $article_asset_count; $image_counter ++ ) {
							$current_asset_url = $article_asset_url[ $image_counter ];
							$current_file_name = basename( $current_asset_url );

							// Save each asset locally using curl.
							$ch = curl_init();

							curl_setopt( $ch, CURLOPT_URL, $current_asset_url );
							curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
							curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
							curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

							$output = curl_exec( $ch );

							$fp = fopen( $local_image_path . '/' . $current_file_name, 'w' );

							fwrite( $fp, $output );
							fclose( $fp );
							curl_close( $ch );
						}

						unset( $response_get_article );

						if ( $delete_downloaded ) {

							$input                   = new DeleteFromQueueRequest();
							$input->username         = $this->username;
							$input->password         = $this->password;
							$input->feedDefinitionId = $this->feed_id;
							$input->uid              = $uid;

							error_log( 'Removing uid ' . $uid . ' from queue.' );

							// While debugging, you may wish to comment out the next line and only delete the content once you know the app is working correctly.
							$response_delete_from_queue = $client->deleteFromQueue( $input );

							error_log( 'delete response: ' . print_r( $response_delete_from_queue->errorOccurred, true ) );
							error_log( 'error: ' . $response_delete_from_queue->errorOccurred );

							unset( $response_delete_from_queue );
						}
						$article_count ++;

					}// End if().
				} // End foreach().
			} // End if().
		} catch ( Exception $e ) {
			error_log( 'Error downloading articles: ' . $e->getMessage() );
		} // End try().

		return $article_count;
	}
}
