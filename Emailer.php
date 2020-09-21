<?php

namespace Emailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Emailer {
    public function __construct(
        $from_email,
        $from_name,
        $from_pass,
        $to_email, # may be comma separated list
        $to_name, # may be comma separated list
        $subject,
        $body,
        $attachment # filepath to attachment
    )
    {
     print "Calling emailer const";
        $this->send_email_at_termination = FALSE;
        $this->from_email = $from_email;
        $this->from_name = $from_name;
        $this->from_pass = $from_pass;

        $this->to_emails = explode(",", $to_email);
        $this->to_names = explode(",", $to_name);

        $this->subject = $subject;
        $this->body = $body;

        $this->attachment = $attachment;
    }

     /**
     * Another case where this isn't directly related to CC so I would pull it out of
     * the class and either add it to a utility class or just make it a stand-alone
     * function in the script that is implemented it.
     */
    public function send_email()
    {
        echo "sending mail\n";
        //Create a new PHPMailer instance
        $mail = new PHPMailer;

        //Tell PHPMailer to use SMTP
        $mail->isSMTP();
        $mail->Mailer = "smtp";

        $mail->SMTPDebug = 0;
        $mail->SMTPAuth   = TRUE;
        $mail->SMTPSecure = "tls";


        //Set the hostname of the mail server
        $mail->Host = 'smtp.gmail.com';
        // use
        // $mail->Host = gethostbyname('smtp.gmail.com');
        // if your network does not support SMTP over IPv6

        //Set the SMTP port number - 587 for authenticated TLS, a.k.a. RFC4409 SMTP submission
        $mail->Port = 587;

        //Username to use for SMTP authentication - use full email address for gmail
        $mail->Username = $this->from_email;

        //Password to use for SMTP authentication
        $mail->Password = $this->from_pass;

        //Set who the message is to be sent from
        $mail->setFrom($this->from_email, $this->from_name);
        $mail->AddReplyTo($this->from_email, $this->from_name);

        //Set who the message is to be sent to
        for ($i=0; $i < count($this->to_emails); $i++) { 
            if(!empty($this->to_names[$i])){
                $mail->addAddress($this->to_emails[$i], $this->to_names[$i]);
            } else {
                $mail->addAddress($this->to_emails[$i], 'Maintainer');
            }
        }
        

        //Set the subject line
        $mail->Subject = $this->subject;

        //Read an HTML message body from an external file, convert referenced images to embedded,
        //convert HTML into a basic plain-text alternative body
        // $mail->msgHTML(file_get_contents('contents.html'), __DIR__);F
        $mail->Body    = $this->body;

        //Replace the plain text body with one created manually
        $altbody = trim(preg_replace('/ +/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', urldecode(html_entity_decode(strip_tags($this->body))))));

        $mail->AltBody = $altbody;

        //Attach an image file
        // $mail->addAttachment('images/phpmailer_mini.png');
        // $mail->addAttachment($this->log_filepath . ".error");
        $mail->addAttachment($this->attachment);
        //send the message, check for errors
        if (!$mail->send()) {
            echo 'Email Mailer Error: '. $mail->ErrorInfo;
print_r($mail);
        } else {
            echo 'Email Message sent!';
        }
    }
}
