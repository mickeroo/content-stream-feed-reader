<?php


/**
 * Used to make the content list request to the Content Stream service.
 *
 * Class GetContentListRequest
 */
class GetContentListRequest {

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
	public $feedDefinitionId;

	/**
	 * Maximum number of results requested from Content Stream.
	 *
	 * @var int
	 */
	public $maxNumberResultsRequested;

	/**
	 * Number of items to skip from the beginning.
	 *
	 * @var int
	 */
	public $offset;

}

/**
 * Used to store the contents of the response of the Get Content List method from the Content Stream service.
 *
 * Class GetContentListResponse
 */
class GetContentListResponse {

	/**
	 * Value of the error returned from the request.
	 *
	 * @var int
	 */
	public $errorOccurred;

	/**
	 * Description of the error.
	 *
	 * @var string
	 */
	public $errorDescription;

	/**
	 * List of Content IDs returned by the request.
	 *
	 * @var array
	 */
	public $arrUid;

	/**
	 * List of Titles returned by the request.
	 *
	 * @var array
	 */
	public $arrTitle;

	/**
	 * List of published dates for the content returned by the request.
	 *
	 * @var array
	 */
	public $arrDateTime;

	/**
	 * Number of articles in the queue for retrieval.
	 *
	 * @var int
	 */
	public $totalNumberInQueue;

	/**
	 * Indicates whether more results are available.
	 *
	 * @var int
	 */
	public $moreResults;

	/**
	 * Stating offset for accessing content.
	 *
	 * @var int
	 */
	public $startingOffset;

	/**
	 * Ending offset for accessing content.
	 *
	 * @var int
	 */
	public $endingOffset;

	/**
	 * Used to pass back the feedDefinitionId of a temporary feed created for previewing results.
	 *
	 * @var int
	 */
	public $feedDefinitionId;

}

/**
 * Used to make the article request to the Content Stream service.
 *
 * Class GetArticleRequest
 */
class GetArticleRequest {

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
	 * Content Stream article ID number.
	 *
	 * @var string
	 */
	public $uid;

	/**
	 * Content Stream feed ID number.
	 *
	 * @var string
	 */
	public $feedDefinitionId;

}

/**
 * Used to store the contents of the response of the Get Article method from the Content Stream service.
 *
 * Class GetArticleResponse
 */
class GetArticleResponse {

	/**
	 * Value of the error returned from request.
	 *
	 * @var int
	 */
	public $errorOccurred;

	/**
	 * Description of the error.
	 *
	 * @var string
	 */
	public $errorDescription;

	/**
	 * URL to the article's XML file.
	 *
	 * @var string
	 */
	public $articleXMLURL;

	/**
	 * List of URLs of assets in the article.
	 *
	 * @var array
	 */
	public $arrArticleAssetURL;

	/**
	 * List of filenames of assets in the article.
	 *
	 * @var array
	 */
	public $arrArticleAssetFileName;

}

/**
 * Used to request that the specified item be removed from the queue.
 *
 * Class DeleteFromQueueRequest
 */
class DeleteFromQueueRequest {

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
	public $feedDefinitionId;

	/**
	 * Article ID to be removed from the queue.
	 *
	 * @var int
	 */
	public $uid;

}

/**
 * Used to store the response from the Delete from Queue request.
 *
 * Class DeleteFromQueueResponse
 */
class DeleteFromQueueResponse {

	/**
	 * Value of the error returned from the request.
	 *
	 * @var int
	 */
	public $errorOccurred;

	/**
	 * Description of the error.
	 *
	 * @var string
	 */
	public $errorDescription;

}
