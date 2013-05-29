<?php
/**
 * @licence http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @author Øystein Riiser Gundersen <oysteinrg@gmail.com>
 */

namespace UAM\Bundle\PostmarkBundle\SwiftMailer;

use MZ\PostmarkBundle\Postmark\Message;

use \Swift_Events_EventListener;
use \Swift_Mime_HeaderSet;
use \Swift_Mime_Message;
use \Swift_Transport;

/**
 * A SwiftMailer transport implementation for the 
 * {@link http://postmarkapp.com/ Postmark} email delivery API for transactional
 * email.
 *  
 * Postmark is *not* for bulk email, but multiple recipients are still supported 
 * by posting the email once for each address. 
 *  
 * Bcc and Cc headers are silently ignored as these are not supported by Postmark.
 *  
 * Usage:
 * <code>
 *    $transport = Swift_PostmarkTransport::newInstance('YOUR-POSTMARK-API-KEY')
 *    $mailer = Swift_Mailer::newInstance($transport);
 *    $message = Swift_Message::newInstance('Wonderful Subject')
 *      ->setFrom(array('sender@mydomain.com' => 'John Doe'))
 *      ->setTo(array('receiver@otherdomain.org' => 'Jane Doe'))
 *      ->setBody('Here is the message itself');
 *    $mailer->send($message);
 * </code>
 *  
 */
class PostmarkTransport implements Swift_Transport {
    
    /** @var array */
    protected $IGNORED_HEADERS = array('Content-Type', 'Date');
    
    /** @var array */
    protected $UNSUPPORTED_HEADERS = array(); //'Bcc', 'Cc');

    protected $postmark_message;

    /**
     * @param string $postmark_api_token Postmark API key
     * @param string|array $from Postmark sender signature email
     * @param string $postmark_uri Postmark HTTP service URI
     */
    public function __construct(Message $postmark_message) {
        $this->postmark_message = $postmark_message;
    }
       
    public function isStarted()
    {
        return false;
    }
    
    public function start()
    {

    }

    public function stop()
    {

    }

    /**
     * @param Swift_Mime_Message $message
     * @param array $failed_recipients
     * @return int
     */
    public function send(Swift_Mime_Message $message, &$failed_recipients = NULL)
    {        
        $failed_recipients = (array)$failed_recipients;
        $postmark = $this->getPostmarkMessage($message);

        $send_count = 0;

        try {
            $response = @json_decode($message->send(), true);
            if ($response['ErrorCode'] == 0) {
                $send_count = count($response['To']);
            }
        }
        catch (Exception $e) {}

        return $send_count;
    }
  
    public function registerPlugin(Swift_Events_EventListener $plugin) {
        // TODO
    } 

    protected function getPostmarkMessage(Swift_Mime_Message $message)
    {
        $postmark = $this->getPostmarkMessageService();

        $headers = $this->processHeaders($message->getHeaders());

        $postmark
            ->setSubject($headers->get('Subject')->getFieldBody());
 
        $headers->remove('Subject');

        $postmark
            ->setSubject($headers->get('From')->getFieldBody());

        $headers->remove('From');

        $postmark
            ->setReplyTo($message->getReplyTo())
            ->setTextMessage($message->getBody());

        if (!is_null($html_part = $this->getMIMEPart($message, 'text/html')))
            $postmark->setHtmlMessage($html_part->getBody());

        foreach ($headers->get('To')->getNameAddresses() as $email => $name) {
            $postmark->addTo($email, $name);
        }

        foreach ($headers->get('Cc')->getNameAddresses() as $email => $name) {
            $postmark->addCC($email, $name);
        }

        foreach ($headers->get('Bcc')->getNameAddresses() as $email => $name) {
            $postmark->addBCC($email, $name);
        }

        foreach ($headers as $header) {
            $postmark->setHeader(
                $header->getFieldName(),
                $header->getFieldBody()
            );
        }

        return $postmark;        
    }

    /**
     * @param Swift_Mime_Message $message
     * @param string $mime_type
     * @return Swift_Mime_MimePart
     */
    protected function getMIMEPart(Swift_Mime_Message $message, $mime_type) {
        $html_part = NULL;
        foreach ($message->getChildren() as $part) {
            if (strpos($part->getContentType(), 'text/html') === 0)
                $html_part = $part;
        }
        return $html_part;
    }
    
    /**
     * @param Swift_Mime_HeaderSet $message
     */
    protected function processHeaders(Swift_Mime_HeaderSet $headers) {
        foreach ($this->IGNORED_HEADERS as $header_name) {
            $headers->remove($header_name);
        }

        foreach ($this->UNSUPPORTED_HEADERS as $header_name) {
            if ($headers->has($header_name))
                throw new Swift_PostmarkTransportException(
                    "Postmark does not support the '{$header_name}' header"
                );
        }

        return $headers;
    }

    protected function getPostmarkMessageService()
    {
        return $this->postmark_message;
    }
}