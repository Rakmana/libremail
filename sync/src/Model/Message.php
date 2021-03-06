<?php

namespace App\Model;

use Fn
  , PDO
  , DateTime
  , App\Model
  , Belt\Belt
  , PDOException
  , ForceUTF8\Encoding
  , Particle\Validator\Validator
  , Pb\Imap\Message as ImapMessage
  , App\Traits\Model as ModelTrait
  , App\Exceptions\Validation as ValidationException
  , App\Exceptions\DatabaseUpdate as DatabaseUpdateException
  , App\Exceptions\DatabaseInsert as DatabaseInsertException;

class Message extends Model
{
    public $id;
    public $to;
    public $cc;
    public $bcc;
    public $from;
    public $date;
    public $size;
    public $seen;
    public $draft;
    public $synced;
    public $recent;
    public $flagged;
    public $deleted;
    public $subject;
    public $charset;
    public $answered;
    public $reply_to;
    public $date_str;
    public $unique_id;
    public $folder_id;
    public $text_html;
    public $account_id;
    public $message_id;
    public $message_no;
    public $text_plain;
    public $references;
    public $created_at;
    public $in_reply_to;
    public $attachments;

    private $unserializedAttachments;

    // Options
    const OPT_TRUNCATE_FIELDS = 'truncate_fields';

    use ModelTrait;

    public function getData()
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'from' => $this->from,
            'date' => $this->date,
            'size' => $this->size,
            'seen' => $this->seen,
            'draft' => $this->draft,
            'synced' => $this->synced,
            'recent' => $this->recent,
            'flagged' => $this->flagged,
            'deleted' => $this->deleted,
            'subject' => $this->subject,
            'charset' => $this->charset,
            'answered' => $this->answered,
            'reply_to' => $this->reply_to,
            'date_str' => $this->date_str,
            'unique_id' => $this->unique_id,
            'folder_id' => $this->folder_id,
            'text_html' => $this->text_html,
            'account_id' => $this->account_id,
            'message_id' => $this->message_id,
            'message_no' => $this->message_no,
            'text_plain' => $this->text_plain,
            'references' => $this->references,
            'created_at' => $this->created_at,
            'in_reply_to' => $this->in_reply_to,
            'attachments' => $this->attachments
        ];
    }

    public function getFolderId()
    {
        return (int) $this->folder_id;
    }

    public function getUniqueId()
    {
        return (int) $this->unique_id;
    }

    public function getAccountId()
    {
        return (int) $this->account_id;
    }

    public function isSynced()
    {
        return Fn\intEq( $this->synced, 1 );
    }

    public function isDeleted()
    {
        return Fn\intEq( $this->deleted, 1 );
    }

    public function getAttachments()
    {
        if ( ! is_null( $this->unserializedAttachments ) ) {
            return $this->unserializedAttachments;
        }

        $this->unserializedAttachments = @unserialize( $this->attachments );

        return $this->unserializedAttachments;
    }

    public function getByIds( $ids )
    {
        return $this->db()
            ->select()
            ->from( 'messages' )
            ->whereIn( 'id', $ids )
            ->execute()
            ->fetchAll( PDO::FETCH_CLASS, $this->getClass() );
    }

    /**
     * Returns a list of integer unique IDs given an account ID
     * and a folder ID to search. This fetches IDs in pages to
     * not exceed any memory limits on the query response.
     * @param int $accountId
     * @param int $folderId
     * @return array of ints
     */
    public function getSyncedIdsByFolder( $accountId, $folderId )
    {
        $ids = [];
        $limit = 10000;
        $count = $this->countSyncedIdsByFolder( $accountId, $folderId );

        for ( $offset = 0; $offset < $count; $offset += $limit ) {
            $ids = array_merge(
                $ids,
                $this->getPagedSyncedIdsByFolder(
                    $accountId,
                    $folderId,
                    $offset,
                    $limit
                ));
        }

        return $ids;
    }

    /**
     * Returns a count of unique IDs for a folder.
     * @param int $accountId
     * @param int $folderId
     * @return int
     */
    public function countSyncedIdsByFolder( $accountId, $folderId )
    {
        $this->requireInt( $folderId, "Folder ID" );
        $this->requireInt( $accountId, "Account ID" );
        $messages = $this->db()
            ->select()
            ->clear()
            ->count( 1, 'count' )
            ->from( 'messages' )
            ->where( 'synced', '=', 1 )
            ->where( 'deleted', '=', 0 )
            ->where( 'folder_id', '=', $folderId )
            ->where( 'account_id', '=', $accountId )
            ->execute()
            ->fetch();

        return ( $messages ) ? $messages[ 'count' ] : 0;
    }

    /**
     * Returns message IDs for the thread ID computation.
     * @param int $accountId
     * @param int $folderId
     * @return array of ints
     */
    public function getIdsForThreadingByFolder( $accountId, $folderId )
    {
        $ids = [];
        $limit = 10000;
        $count = $this->countIdsForThreadingByFolder( $accountId, $folderId );

        for ( $offset = 0; $offset < $count; $offset += $limit ) {
            $ids = array_merge(
                $ids,
                $this->getPagedIdsForThreadingByFolder(
                    $accountId,
                    $folderId,
                    $offset,
                    $limit
                ));
        }

        return $ids;
    }

    /**
     * Returns a count of messages without a thread ID for a
     * given folder and account ID.
     * @param int $accountId
     * @param int $folderId
     * @return int
     */
    public function countIdsForThreadingByFolder( $accountId, $folderId )
    {
        $this->requireInt( $folderId, "Folder ID" );
        $this->requireInt( $accountId, "Account ID" );
        $messages = $this->db()
            ->select()
            ->clear()
            ->count( 1, 'count' )
            ->from( 'messages' )
            ->where( 'folder_id', '=', $folderId )
            ->where( 'account_id', '=', $accountId )
            ->whereNull( 'thread_id' )
            ->execute()
            ->fetch();

        return ( $messages ) ? $messages[ 'count' ] : 0;
    }

    /**
     * Adds a thread ID to the message. Searches forward and back looking
     * for any thread ID that is saved on any message. If found, update
     * this message with that thread ID.
     */
    public function updateThreadId()
    {
        if ( $this->thread_id ) {
            return;
        }

        // This will store all messages found in the chain. Everything in
        // here will get the best thread ID found.
        $ids = [ $this->id ];
        // Where the Message ID is this Reply-To
        $pastThreadId = $this->findPastThreadId( $this->in_reply_to, $ids );
        $futureThreadId = $this->findFutureThreadId( $this->message_id, $ids );
        $threadId = ( $pastThreadId ) ?: $futureThreadId;

        if ( ! $threadId ) {
            $threadId = $this->id;
        }

        $this->saveThreadId( $threadId, $ids );
    }

    /**
     * Finds the message by using the Reply-To address from a message.
     * Looks up by Message ID, and teturns a thread ID if it finds one.
     * If not, it recursives through the history until the end.
     * @param string $inReplyTo
     * @param array $ids Reference to all message IDs found
     * @param array $loopIds Reference list to prevent infinite loops
     * @return int | NULL
     */
    private function findPastThreadId( $inReplyTo, &$ids, &$loopIds = [] )
    {
        if ( ! $inReplyTo || in_array( $inReplyTo, $loopIds ) ) {
            return NULL;
        }

        $messages = $this->db()
            ->select()
            ->from( 'messages' )
            ->where( 'message_id', '=', $inReplyTo )
            ->execute()
            ->fetchAll( PDO::FETCH_CLASS, $this->getClass() );

        if ( ! $messages ) {
            return NULL;
        }

        $loopIds[] = $inReplyTo;

        foreach ( $messages as $message ) {
            $ids[] = $message->id;
        }

        foreach ( $messages as $message ) {
            if ( $message->thread_id ) {
                return $message->thread_id;
            }

            if ( $message->in_reply_to ) {
                $threadId = $this->findPastThreadId(
                    $message->in_reply_to,
                    $ids,
                    $loopIds );

                if ( $threadId ) {
                    return $threadId;
                }
            }
        }

        return NULL;
    }

    /**
     * Finds the message by using the Message-ID from a message.
     * Looks up by InReplyTo, and teturns a thread ID if it finds one.
     * If not, it recursives through the future until the end.
     * @param string $messageId
     * @param array $ids Reference to all message IDs found
     * @param array $loopIds Reference list to prevent infinite loops
     * @return int | null
     */
    private function findFutureThreadId( $messageId, &$ids, &$loopIds = [] )
    {
        if ( ! $messageId || in_array( $messageId, $loopIds ) ) {
            return;
        }

        $messages = $this->db()
            ->select()
            ->from( 'messages' )
            ->where( 'in_reply_to', '=', $messageId )
            ->execute()
            ->fetchAll( PDO::FETCH_CLASS, $this->getClass() );

        if ( ! $messages ) {
            return NULL;
        }

        $loopIds[] = $messageId;

        foreach ( $messages as $message ) {
            $ids[] = $message->id;
        }

        foreach ( $messages as $message ) {
            if ( $message->thread_id ) {
                return $message->thread_id;
            }

            if ( $message->message_id ) {
                $threadId = $this->findFutureThreadId(
                    $message->message_id,
                    $ids,
                    $loopIds );

                if ( $threadId ) {
                    return $threadId;
                }
            }
        }

        return NULL;
    }

    /**
     * This method is called by getSyncedIdsByFolder to return a
     * page of results.
     * @param int $accountId
     * @param int $folderId
     * @param int $offset
     * @param int $limit
     * @return array
     */
    private function getPagedSyncedIdsByFolder( $accountId, $folderId, $offset = 0, $limit = 100 )
    {
        $ids = [];
        $this->requireInt( $folderId, "Folder ID" );
        $this->requireInt( $accountId, "Account ID" );
        $messages = $this->db()
            ->select([ 'unique_id' ])
            ->from( 'messages' )
            ->where( 'synced', '=', 1 )
            ->where( 'deleted', '=', 0 )
            ->where( 'folder_id', '=', $folderId )
            ->where( 'account_id', '=', $accountId )
            ->limit( $limit, $offset )
            ->execute()
            ->fetchAll();

        if ( ! $messages ) {
            return $ids;
        }

        foreach ( $messages as $message ) {
            $ids[] = $message[ 'unique_id' ];
        }

        return $ids;
    }

    /**
     * This method is called by getIdsForThreadingByFolder to return a
     * page of results.
     * @param int $accountId
     * @param int $folderId
     * @param int $offset
     * @param int $limit
     * @return array of ints
     */
    private function getPagedIdsForThreadingByFolder( $accountId, $folderId, $offset = 0, $limit = 100 )
    {
        $ids = [];
        $this->requireInt( $folderId, "Folder ID" );
        $this->requireInt( $accountId, "Account ID" );
        $messages = $this->db()
            ->select([ 'id' ])
            ->from( 'messages' )
            ->where( 'folder_id', '=', $folderId )
            ->where( 'account_id', '=', $accountId )
            ->whereNull( 'thread_id' )
            ->limit( $limit, $offset )
            ->execute()
            ->fetchAll();

        if ( ! $messages ) {
            return $ids;
        }

        foreach ( $messages as $message ) {
            $ids[] = $message[ 'id' ];
        }

        return $ids;
    }

    /**
     * Create or updates a message record.
     * @param array $data
     * @throws ValidationException
     * @throws DatabaseUpdateException
     * @throws DatabaseInsertException
     */
    public function save( $data = [] )
    {
        $val = new Validator;
        $val->required( 'folder_id', 'Folder ID' )->integer();
        $val->required( 'unique_id', 'Unique ID' )->integer();
        $val->required( 'account_id', 'Account ID' )->integer();
        // Optional fields
        $val->required( 'size', 'Size' )->integer();
        $val->required( 'message_no', 'Message Number' )->integer();
        $val->optional( 'date', 'Date' )->datetime( DATE_DATABASE );
        $val->optional( 'subject', 'Subject' )->lengthBetween( 0, 270 );
        $val->optional( 'charset', 'Charset' )->lengthBetween( 0, 100 );
        $val->optional( 'date_str', 'RFC Date' )->lengthBetween( 0, 100 );
        $val->optional( 'seen', 'Seen' )->callback([ $this, 'isValidFlag' ]);
        $val->optional( 'message_id', 'Message ID' )->lengthBetween( 0, 250 );
        $val->optional( 'draft', 'Draft' )->callback([ $this, 'isValidFlag' ]);
        $val->optional( 'in_reply_to', 'In-Reply-To' )->lengthBetween( 0, 250 );
        $val->optional( 'recent', 'Recent' )->callback([ $this, 'isValidFlag' ]);
        $val->optional( 'flagged', 'Flagged' )->callback([ $this, 'isValidFlag' ]);
        $val->optional( 'deleted', 'Deleted' )->callback([ $this, 'isValidFlag' ]);
        $val->optional( 'answered', 'Answered' )->callback([ $this, 'isValidFlag' ]);
        $this->setData( $data );
        $data = $this->getData();

        if ( ! $val->validate( $data ) ) {
            throw new ValidationException(
                $this->getErrorString(
                    $val,
                    "This message is missing required data."
                ));
        }

        // Update flags to have the right data type
        $this->updateFlagValues( $data, [
            'seen', 'draft', 'recent', 'flagged',
            'deleted', 'answered'
        ]);
        $this->updateUtf8Values( $data, [
            'subject', 'text_html', 'text_plain'
        ]);

        // Check if this message exists
        $exists = $this->db()
            ->select()
            ->from( 'messages' )
            ->where( 'folder_id', '=', $this->folder_id )
            ->where( 'unique_id', '=', $this->unique_id )
            ->where( 'account_id', '=', $this->account_id )
            ->execute()
            ->fetchObject();
        $updateMessage = function ( $db, $id, $data ) {
            return $db
                ->update( $data )
                ->table( 'messages' )
                ->where( 'id', '=', $id )
                ->execute();
        };
        $insertMessage = function ( $db, $data ) {
            return $db
                ->insert( array_keys( $data ) )
                ->into( 'messages' )
                ->values( array_values( $data ) )
                ->execute();
        };

        if ( $exists ) {
            $this->id = $exists->id;
            unset( $data[ 'id' ] );
            unset( $data[ 'created_at' ] );

            try {
                $updated = $updateMessage( $this->db(), $this->id, $data );
            }
            catch ( PDOException $e ) {
                // Check for bad UTF-8 errors
                if ( strpos( $e->getMessage(), "Incorrect string value:" ) ) {
                    $data[ 'subject' ] = Encoding::fixUTF8( $data[ 'subject' ] );
                    $data[ 'text_html' ] = Encoding::fixUTF8( $data[ 'text_html' ] );
                    $data[ 'text_plain' ] = Encoding::fixUTF8( $data[ 'text_plain' ] );
                    $newMessageId = $updateMessage( $this->db(), $data );
                }
                else {
                    throw $e;
                }
            }

            if ( ! Belt::isNumber( $updated ) ) {
                throw new DatabaseUpdateException(
                    MESSAGE,
                    $this->db()->getError() );
            }

            return;
        }

        $createdAt = new DateTime;
        unset( $data[ 'id' ] );
        $data[ 'created_at' ] = $createdAt->format( DATE_DATABASE );

        try {
            $newMessageId = $insertMessage( $this->db(), $data );
        }
        catch ( PDOException $e ) {
            // Check for bad UTF-8 errors
            if ( strpos( $e->getMessage(), "Incorrect string value:" ) ) {
                $data[ 'subject' ] = Encoding::fixUTF8( $data[ 'subject' ] );
                $data[ 'text_html' ] = Encoding::fixUTF8( $data[ 'text_html' ] );
                $data[ 'text_plain' ] = Encoding::fixUTF8( $data[ 'text_plain' ] );
                $newMessageId = $insertMessage( $this->db(), $data );
            }
            else {
                throw $e;
            }
        }

        if ( ! $newMessageId ) {
            throw new DatabaseInsertException(
                MESSAGE,
                $this->getError() );
        }

        $this->id = $newMessageId;
    }

    /**
     * Saves the meta information and content for a message as data
     * on the class object.
     * @param array $meta
     * @param array $options
     */
    public function setMessageData( ImapMessage $message, array $options = [] )
    {
        if ( Fn\get( $options, self::OPT_TRUNCATE_FIELDS ) === TRUE ) {
            $message->subject = substr( $message->subject, 0, 270 );
        }

        $this->setData([
            'size' => $message->size,
            'date' => $message->date,
            'to' => $message->toString,
            'unique_id' => $message->uid,
            'from' => $message->fromString,
            'subject' => $message->subject,
            'charset' => $message->charset,
            'seen' => $message->flags->seen,
            'text_html' => $message->textHtml,
            'draft' => $message->flags->draft,
            'date_str' => $message->dateString,
            'text_plain' => $message->textPlain,
            'message_id' => $message->messageId,
            'recent' => $message->flags->recent,
            'message_no' => $message->messageNum,
            'references' => $message->references,
            'in_reply_to' => $message->inReplyTo,
            'flagged' => $message->flags->flagged,
            'deleted' => $message->flags->deleted,
            'answered' => $message->flags->answered,
            // The cc and inReplyTo fields come in as arrays with the
            // address as the index and the name as the value. Create
            // the proper comma separated strings for these fields.
            'cc' => $this->formatAddress( $message->cc ),
            'bcc' => $this->formatAddress( $message->bcc ),
            'reply_to' => $this->formatAddress( $message->replyTo ),
            'attachments' => $this->formatAttachments( $message->getAttachments() )
        ]);
    }

    /**
     * Takes in an array of message unique IDs and marks them all as
     * deleted in the database.
     * @param array $uniqueIds
     */
    public function markDeleted( $uniqueIds, $accountId, $folderId )
    {
        if ( ! is_array( $uniqueIds ) || ! count( $uniqueIds ) ) {
            return;
        }

        $this->requireInt( $folderId, "Folder ID" );
        $this->requireInt( $accountId, "Account ID" );
        $updated = $this->db()
            ->update([ 'deleted' => 1 ])
            ->table( 'messages' )
            ->where( 'folder_id', '=', $folderId )
            ->where( 'account_id', '=', $accountId )
            ->whereIn( 'unique_id', $uniqueIds )
            ->execute();

        if ( ! Belt::isNumber( $updated ) ) {
            throw new DatabaseUpdateException(
                MESSAGE,
                $this->getError() );
        }
    }

    /**
     * Saves a thread ID for the given messages.
     * @param int $id
     * @param int $threadId
     */
    public function saveThreadId( $threadId, $messageIds )
    {
        $this->requireInt( $threadId, "Thread ID" );
        $this->requireInt( $this->id, "Message ID" );
        $updated = $this->db()
            ->update([ 'thread_id' => $threadId ])
            ->table( 'messages' )
            ->whereIn( 'id', $messageIds )
            ->execute();

        if ( ! Belt::isNumber( $updated ) ) {
            throw new DatabaseUpdateException(
                MESSAGE,
                $this->getError() );
        }
    }

    /**
     * Takes in an array of addresses and formats them in a list.
     * @return string
     */
    private function formatAddress( $addresses )
    {
        if ( ! is_array( $addresses ) ) {
            return NULL;
        }

        $formatted = [];

        foreach ( $addresses as $address ) {
            $formatted[] = sprintf(
                "%s <%s>",
                $address->getName(),
                $address->getEmail() );
        }

        return implode( ", ", $formatted );
    }

    /**
     * Attachments need to be serialized. They come in as an array
     * of objects with name, path, and id fields.
     * @param Attachment array $attachments
     * @return string
     */
    private function formatAttachments( $attachments )
    {
        if ( ! is_array( $attachments ) ) {
            return NULL;
        }

        $formatted = [];

        foreach ( $attachments as $attachment ) {
            $formatted[] = $attachment->toArray();
        }

        return json_encode( $formatted, JSON_UNESCAPED_SLASHES );
    }
}