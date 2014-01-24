<?php

/**
 * Implements a JobQueue class for the Amazon SQS
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Extensions
 */

use Aws\Sqs\SqsClient;
use Aws\Sqs\Exception\SqsException;

/**
 * A job queue that uses the Amazon SQS service as a back-end.
 *
 * @see JobQueue
 * @author Tyler Romeo <tylerromeo@gmail.com>
 */
class JobQueueAmazonSqs extends JobQueue {
	/**
	 * Cached size of the entire queue
	 * @var int
	 */
	private $size = null;

	/**
	 * Cached size of the queue's acquired jobs
	 * @var int
	 */
	private $ackSize = null;

	/**
	 * Cached size of the queue's delayed jobs
	 * @var int
	 */
	private $delaySize = null;

	/**
	 * Name of the queue in AWS SQS
	 * @var string
	 */
	private $queueName = null;

	/**
	 * Descriptor from AWS of the current queue
	 * @var array
	 */
	private $queue;

	/**
	 * The AWS client
	 * @var Aws\Sqs\SqsClient
	 */
	private $client;

	function __construct( array $params ) {
		global $wgAWSCredentials, $wgAWSRegion, $wgAWSUseHTTPS;

		parent::__construct( $params );

		$this->queueName = "mediawiki-{$this->wiki}-jobqueue-{$this->type}";

		if ( isset( $params['aws-https'] ) ) {
			$useHTTPS = (bool)$params['aws-https'];
		} else {
			$useHTTPS = (bool)$wgAWSUseHTTPS;
		}

		$this->client = SqsClient::factory( array(
			'key' => isset( $params['aws-key'] ) ? $params['aws-key'] : $wgAWSCredentials['key'],
			'secret' => isset( $params['aws-secret'] ) ? $params['aws-secret'] : $wgAWSCredentials['secret'],
			'region' => isset( $params['aws-region'] ) ? $params['aws-region'] : $wgAWSRegion,
			'scheme' => $useHTTPS ? 'https' : 'http',
			'ssl.cert' => $useHTTPS ? true : null
		) );
	}

	public function connect() {
		global $wgMemc;

		if ( $this->queue !== null ) {
			return;
		}

		$existenceKey = wfForeignMemcKey( $this->wiki, 'awssqs', 'existence', $this->queueName );

		// Use memcached to check for queue existence in order to avoid
		// a request to AWS.
		if ( !$wgMemc->get( $existenceKey ) ) {
			try {
				$this->queue = $this->client->createQueue( array(
					'QueueName' => $this->queueName
				) );
				$this->client->setQueueAttributes( array(
					'QueueUrl' => $this->queue['QueueUrl'],
					'Attributes' => array(
						'VisibilityTimeout' => $this->claimTTL
					)
				) );
			} catch ( SqsException $e ) {
				throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
			}

			$wgMemc->set( $existenceKey, 1 );
		}
	}

	function supportedOrders() {
		return array( 'random' );
	}

	function optimalOrder() {
		return 'random';
	}

	function doAck( Job $job ) {
		$this->connect();
		$this->ackSize = null;

		try {
			$this->client->deleteMessage( array(
				'QueueUrl' => $this->queue['QueueUrl'],
				'ReceiptHandle' => $job->metadata['aws_receipt']
			) );
		} catch ( SqsException $e ) {
			throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
		}

		return true;
	}

	function doBatchPush( array $jobs, $flags ) {
		$this->size = null;

		$entries = array();
		$now = time();
		foreach ( $jobs as $i => $job ) {
			$entries[$i] = array(
				'Id' => $i,
				'MessageBody' => serialize( array(
					'cmd' => $job->getType(),
					'namespace' => $job->getTitle()->getNamespace(),
					'title' => $job->getTitle()->getText(),
					'params' => $job->getParams()
				) ),
			);

			if ( $this->checkDelay ) {
				$delaySeconds = $job->getReleaseTimestamp() - $now;
				if ( $delaySeconds >= 0 ) {
					$entries[$i]['DelaySeconds'] = $delaySeconds;
				}
			}
		}

		$this->connect();
		try {
			$res = $this->client->sendMessageBatch( array(
				'QueueUrl' => $this->queue['QueueUrl'],
				'Entries' => $entries
			) );
		} catch ( SqsException $e ) {
			throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
		}

		if ( count( $res['Failed'] ) ) {
			$exc = new SqsException( $res['Failed'][0]['Message'], $res['Failed'][0]['Code'] );
			throw new MWException( 'Jobs failed to push to the AWS SQS Job Queue.', 0, $exc );
		}

		foreach ( $res['Successful'] as $success ) {
			if ( $success['MD5OfMessageBody'] !== md5( $entries[$success['Id']]['MessageBody'] ) ) {
				throw new MWException( 'Invalid MD5 of message body received.' );
			}
		}

		return true;
	}

	function doGetAcquiredCount() {
		$this->getAttributes();

		return $this->ackSize;
	}

	function doGetSize() {
		$this->getAttributes();

		return $this->size;
	}

	function doIsEmpty() {
		return !$this->doGetSize();
	}

	function doPop() {
		return $this->getJobs( 1, true )->current();
	}

	function getAllQueuedJobs() {
		return $this->getJobs();
	}

	/**
	 * Gets a number of jobs from the SQS queue.
	 *
	 * @param int|bool $limit Max number of jobs to retrieve, or false for no limit
	 * @param bool $claim Whether to claim the jobs
	 * @throws MWException
	 * @return MappedIterator with the jobs
	 * @see JobQueue::getAllQueuedJobs
	 */
	private function getJobs( $limit = false, $claim = false ) {
		$this->connect();

		try {
			$msgs = $this->client->receiveMessage( array(
				'QueueUrl' => $this->queue['QueueUrl'],
				'MaxNumberOfMessages' => $limit === false ? null : $limit,
				'VisibilityTimeout' => $claim ? $this->claimTTL : 0
			) );
		} catch ( SqsException $e ) {
			throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
		}

		$that = $this;
		$callback = function( $msg ) use ( $that ) {
			if( md5( $msg['Body'] ) !== $msg['MD5OfBody'] ) {
				throw new MWException( 'Invalid MD5 of message body received.' );
			}

			$desc = unserialize( $msg['Body'] );
			$title = Title::makeTitle( $desc['namespace'], $desc['title'] );

			$job = Job::factory( $desc['cmd'], $title, $desc['params'], $msg['MessageId'] );
			$job->metadata['aws_receipt'] = $msg['ReceiptHandle'];

			return $job;
		};

		// Clear the count cache.
		$this->ackSize = $this->size = null;

		return new MappedIterator( $msgs['Messages'], $callback );
	}

	function doDelete() {
		$entries = array();
		foreach ( $this->getAllQueuedJobs() as $i => $job ) {
			$entries[] = array(
				'Id' => $i,
				'ReceiptHandle' => $job->metadata['aws_receipt']
			);
		}

		if ( !$entries ) {
			return;
		}

		// Should already be connected from $this->getAllQueuedJobs, but just in case.
		$this->connect();
		try {
			$res = $this->client->deleteMessageBatch( array(
				'QueueUrl' => $this->queue['QueueUrl'],
				'Entries' => $entries
			) );
		} catch ( SqsException $e ) {
			throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
		}

		if ( count( $res['Failed'] ) ) {
			$exc = new SqsException( $res['Failed'][0]['Message'], $res['Failed'][0]['Code'] );
			throw new MWException( 'Jobs failed to delete from AWS SQS Job Queue.', 0, $exc );
		}
	}

	function doFlushCaches() {
		$this->size = $this->ackSize = $this->delaySize = null;
	}

	private function getAttributes() {
		if ( $this->size !== null && $this->ackSize !== null && $this->delaySize !== null ) {
			return;
		}

		$this->connect();
		try {
			$attrs = $this->client->getQueueAttributes( array(
				'QueueUrl' => $this->queue['QueueUrl'],
				'AttributeNames' => array(
					'ApproximateNumberOfMessages',
					'ApproximateNumberOfMessagesNotVisible',
					'ApproximateNumberOfMessagesDelayed'
				)
			) );
		} catch ( SqsException $e ) {
			throw new MWException( "Amazon SQS error: {$e->getMessage()}", 0, $e );
		}

		$this->size = (int)$attrs['Attributes']['ApproximateNumberOfMessages'];
		$this->ackSize = (int)$attrs['Attributes']['ApproximateNumberOfMessagesNotVisible'];
		$this->delaySize = (int)$attrs['Attributes']['ApproximateNumberOfMessagesDelayed'];
	}
}
