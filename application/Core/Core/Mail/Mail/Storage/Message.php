<?php


namespace Core\Core\Mail\Mail\Storage;

use Core\Core\Mail\Mail\Headers;
use Zend\Mail\Header\HeaderInterface;
use Zend\Mime;
use Zend\Mail\Storage\Exception;
use Zend\Mail\Storage\AbstractStorage;
use Zend\Stdlib\ErrorHandler;

class Message extends \Zend\Mail\Storage\Message
{
    public function __construct(array $params)
    {
        if (isset($params['file'])) {
            if (!is_resource($params['file'])) {
                ErrorHandler::start();
                $params['raw'] = file_get_contents($params['file']);
                $error = ErrorHandler::stop();
                if ($params['raw'] === false) {
                    throw new Exception\RuntimeException('could not open file', 0, $error);
                }
            } else {
                $params['raw'] = stream_get_contents($params['file']);
            }
        }

        if (!empty($params['flags'])) {
            // set key and value to the same value for easy lookup
            $this->flags = array_combine($params['flags'], $params['flags']);
        }

        if (isset($params['handler'])) {
            if (!$params['handler'] instanceof AbstractStorage) {
                throw new Exception\InvalidArgumentException('handler is not a valid mail handler');
            }
            if (!isset($params['id'])) {
                throw new Exception\InvalidArgumentException('need a message id with a handler');
            }

            $this->mail       = $params['handler'];
            $this->messageNum = $params['id'];
        }

        $params['strict'] = isset($params['strict']) ? $params['strict'] : false;

        if (isset($params['raw'])) {
            self::splitMessage($params['raw'], $this->headers, $this->content, Mime\Mime::LINEEND, $params['strict']);
        } elseif (isset($params['headers'])) {
            if (is_array($params['headers'])) {
                $this->headers = new Headers();
                $this->headers->addHeaders($params['headers']);
            } else {
                if ($params['headers'] instanceof \Zend\Mail\Headers) {
                    $this->headers = $params['headers'];
                } else {
                    if (empty($params['noToplines'])) {
                        self::splitMessage($params['headers'], $this->headers, $this->topLines);
                    } else {
                        $this->headers = Headers::fromString($params['headers']);
                    }
                }
            }

            if (isset($params['content'])) {
                $this->content = $params['content'];
            }
        }
    }

    public function __isset($name)
    {
        $headers = $this->getHeaders();
        if (empty($headers) || !is_object($headers)) {
            return false;
        }
        return $this->getHeaders()->has($name);
    }

    public function isMultipart()
    {
        if (!isset($this->contentType)) {
            return false;
        }

        try {
            return stripos($this->contentType, 'multipart/') === 0;
        } catch (Exception\ExceptionInterface $e) {
            return false;
        }
    }

    public static function splitMessage($message, &$headers, &$body, $EOL = Mime\Mime::LINEEND, $strict = false)
    {
        if ($message instanceof Headers) {
            $message = $message->toString();
        }
        // check for valid header at first line
        $firstline = strtok($message, "\n");
        if (!preg_match('%^[^\s]+[^:]*:%', $firstline)) {
            $headers = array();
            // TODO: we're ignoring \r for now - is this function fast enough and is it safe to assume noone needs \r?
            $body = str_replace(array("\r", "\n"), array('', $EOL), $message);
            return;
        }

        // see @ZF2-372, pops the first line off a message if it doesn't contain a header
        if (!$strict) {
            $parts = explode(':', $firstline, 2);
            if (count($parts) != 2) {
                $message = substr($message, strpos($message, $EOL)+1);
            }
        }

        // find an empty line between headers and body
        // default is set new line
        if (strpos($message, $EOL . $EOL)) {
            list($headers, $body) = explode($EOL . $EOL, $message, 2);
        // next is the standard new line
        } elseif ($EOL != "\r\n" && strpos($message, "\r\n\r\n")) {
            list($headers, $body) = explode("\r\n\r\n", $message, 2);
        // next is the other "standard" new line
        } elseif ($EOL != "\n" && strpos($message, "\n\n")) {
            list($headers, $body) = explode("\n\n", $message, 2);
        // at last resort find anything that looks like a new line
        } else {
            ErrorHandler::start(E_NOTICE|E_WARNING);
            list($headers, $body) = preg_split("%([\r\n]+)\\1%U", $message, 2);
            ErrorHandler::stop();
        }

        $headers = Headers::fromString($headers, $EOL);
    }
}

