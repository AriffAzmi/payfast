<?php

namespace Billow;

use Billow\Contracts\PaymentProcessor;
use Billow\Utilities\Money;
use Exception;
use Illuminate\Http\Request;

class Payfast implements PaymentProcessor
{

    protected $merchant;

    protected $buyer;

    protected $merchantReference;

    protected $amount;

    protected $item;

    protected $output;

    protected $vars;

    protected $response_vars;

    protected $host;

    protected $button;

    protected $status;


    public function __construct()
    {
        $this->merchant = config('payfast.merchant');
    }

    public function getMerchant()
    {
        return $this->merchant;
    }

    public function setBuyer($first, $last, $email)
    {
        $this->buyer = [
            'name_first'    => $first,
            'name_last'     => $last,
            'email_address' => $email
        ];
    }

    public function setMerchantReference($reference)
    {
        $this->merchantReference = $reference;
    }

    public function setItem($item, $description)
    {
        $this->item = [
            'item_name'         => $item,
            'item_description'  => $description,
        ];
    }

    public function setAmount($amount)
    {
        $money = $this->newMoney($amount);
        $this->amount = $money->convertedAmount();
    }

    public function paymentForm($submitButton = true)
    {
        $this->button = $submitButton;

        $this->vars = $this->paymentVars();

        $this->buildQueryString();

        $this->vars['signature'] = md5($this->output);

        return $this->buildForm();
    }

    public function paymentVars()
    {
        return array_merge($this->merchant, $this->buyer, ['m_payment_id' => $this->merchantReference, 'amount' => $this->amount], $this->item);
    }

    public function buildQueryString()
    {
        foreach($this->vars as $key => $val )
        {
            if(!empty($val)) {
                $this->output .= $key .'='. urlencode( trim( $val ) ) .'&';
            }
        }
        $this->output = substr( $this->output, 0, -1 );
        if( isset( $passPhrase ) )
        {
            $this->output .= '&passphrase='.$passPhrase;
        }
    }

    public function buildForm()
    {
        $this->getHost();
        $htmlForm = '<form id="payfast-pay-form" action="https://'.$this->host.'/eng/process" method="post">';
        foreach($this->vars as $name => $value)
        {
            $htmlForm .= '<input type="hidden" name="'.$name.'" value="'.$value.'">';
        }
        if($this->button)
        {
            $htmlForm .= '<button type="submit">'.$this->getSubmitButton().'</button>';
        }
        return $htmlForm.'</form>';
    }

    public function verify($request, $amount)
    {
        $this->setHeader();
        $this->response_vars = $request->all();
        $this->setAmount($amount);
        foreach($this->response_vars as $key => $val)
        {
            $this->vars[$key] = stripslashes($val);
        }
        $this->buildQueryString();
        $this->validSignature($request->get('signature'));
        $this->validateHost($request);
        $this->validateAmount($request->get('amount_gross'));
        $this->status = $request->get('payment_status');
        return $this;
    }

    public function status()
    {
        return $this->status;
    }

    public function setHeader()
    {
        header('HTTP/1.0 200 OK');
        flush();
    }

    public function validSignature($signature)
    {
        if($this->vars['signature'] === $signature)
        {
            return true;
        }else {
            throw new Exception('Invalid Signature');
        }
    }

    public function validateHost($request)
    {
        $hosts = $this->getHosts();

        if( !in_array( $request->server('REMOTE_ADDR'), $hosts ) )
        {
            throw new Exception('Not a valid Host');
        }

        return true;
    }

    public function getHosts()
    {
        $hosts = [];

        foreach(config('payfast.hosts') as $host)
        {
            $ips = gethostbynamel($host);

            if(count($ips) > 0)
            {
                foreach($ips as $ip)
                {
                    $hosts[] = $ip;
                }
            }
        }
        return array_unique($hosts);
    }

    public function validateAmount($grossAmount)
    {
        if($this->amount === $this->newMoney($grossAmount)->getConvertedAmount())
        {
            return true;
        }else {
            throw new Exception('The gross amount does not match the order amount');
        }
    }

    public function newMoney($amount)
    {
        return(is_string($amount) || is_float($amount))
            ? (new Money)->fromString((string)$amount)
            : new Money($amount);
    }

    public function getHost()
    {
        return $this->host = config('payfast.testing') ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
    }

    public function getSubmitButton()
    {
        if(is_string($this->button))
        {
            return $this->button;
        }

        if($this->button == true)
        {
            return 'Pay Now';
        }
        return false;
    }

    public function responseVars()
    {
        return $this->response_vars;
    }

    public function setCancelUrl($url)
    {
        $this->merchant['cancel_url'] = $url;
    }

    public function setReturnUrl($url)
    {
        $this->merchant['return_url'] = $url;
    }
}