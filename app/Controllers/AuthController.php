<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Libraries\CIAuth;
use App\Libraries\Hash;
use App\Models\User;
use App\Models\PasswordResetToken;
use Carbon\Carbon;
use CodeIgniter\HTTP\ResponseInterface;
use PHPUnit\TextUI\XmlConfiguration\Validator;

class AuthController extends BaseController
{
    protected $helpers = ['url', 'form', 'CIMail', 'CIFunctions'];

    public function loginForm()
    {
        $data = [
            'pageTitle' => 'Login',
            'validation' => null
        ];
        return view('backend\pages\auth\login', $data);
    }

    public function loginHandler()
    {
        $fieldType = filter_var($this->request->getVar('login_id'), FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        if ($fieldType == 'email') {
            $isValid = $this->validate([
                'login_id' => [
                    'rules' => 'required|valid_email|is_not_unique[users.email]',
                    'errors' => [
                        'required' => 'Email is required',
                        'valid_email' => 'Please, check the email field. It does not appears to be valid.',
                        'is_not_unique' => 'Email does not exists in our system.'
                    ]
                ],
                'password' => [
                    'rules' => 'required|min_length[5]|max_length[45]',
                    'errors' => [
                        'required' => 'Password is required',
                        'min_length' => 'Password must have at least 5 characters in length',
                        'max_length' => 'Password must not have more than 45 characters'
                    ]
                ]
            ]);
        } else {
            $isValid = $this->validate([
                'login_id' => [
                    'rules' => 'required|is_not_unique[users.username]',
                    'errors' => [
                        'required' => 'Username is required',
                        'is_not_unique' => 'Username does not exists in our system.'
                    ]
                ],
                'password' => [
                    'rules' => 'required|min_length[5]|max_length[45]',
                    'errors' => [
                        'required' => 'Password is required',
                        'min_length' => 'Password must have at least 5 characters in length',
                        'max_length' => 'Password must not have more than 45 characters'
                    ]
                ]
            ]);
        }

        if (!$isValid) {
            return view('backend/pages/auth/login', [
                'pageTitel' => 'Login',
                'validation' => $this->validator
            ]);
        } else {
            $user = new User();
            $userInfo = $user->where($fieldType, $this->request->getVar('login_id'))->first();
            $check_password = Hash::check($this->request->getVar('password'), $userInfo['password']);

            if (!$check_password) {
                return redirect()->route('admin.login.form')->with('fail', 'Wrong Password')->withInput();
            } else {
                CIAuth::setCIAuth($userInfo);
                return redirect()->route('admin.home');
            }
        }
    }

    public function forgotForm()
    {
        $data = array(
            'pageTitle' => 'Forgot password',
            'validation' => null
        );
        return view('backend\pages\auth\forgot', $data);
    }

    public function sendPasswordResetLink()
    {
        $isValid = $this->validate([
            'email' => [
                'rules' => 'required|valid_email|is_not_unique[users.email]',
                'errors' => [
                    'required' => 'Email is required',
                    'valid_email' => 'Please check email field. It does not appears to be valid.',
                    'is_not_unique' => 'Email does not exists in our system.'
                ]
            ]
        ]);

        if (!$isValid) {
            return view('backend/pages/auth/forgot', [
                'pageTitle' => 'Forgot password',
                'validation' => $this->validator,
            ]);
        } else {
            //Get user (admin) details
            $user = new User();
            $user_info = $user->asObject()->where('email', $this->request->getVar('email'))->first();

            //Generate token
            $token = bin2hex(openssl_random_pseudo_bytes(65));

            //Get reset password token
            $password_reset_token = new PasswordResetToken();
            $isOldTokenExist = $password_reset_token->asObject()->where('email', $user_info->email)->first();

            if ($isOldTokenExist) {
                // update existing token
                $password_reset_token->where('email', $user_info->email)
                    ->set(['token' => $token, 'created_at' => Carbon::now()])
                    ->update();
            } else {
                $password_reset_token->insert([
                    'email' => $user_info->email,
                    'token' => $token,
                    'created_at' => Carbon::now()
                ]);
            }

            //Create action link
            //$actionLink = route_to('admin.reset-password', $token);
            $actionLink = base_url(route_to('admin.reset-password', $token));

            $mail_data = array(
                'actionLink' => $actionLink,
                'user' => $user_info,
            );

            $view = \Config\Services::renderer();
            $mail_body = $view->setVar('mail_data', $mail_data)->render('email-templates/forgot-email-template');

            $mailConfig = array(
                'mail_from_email' => env('EMAIL_FROM_ADDRESS'),
                'mail_from_name' => env('EMAIL_FROM_NAME'),
                'mail_recipient_email' => $user_info->email,
                'mail_recipient_name' => $user_info->name,
                'mail_subject' => 'Reset Password',
                'mail_body' => $mail_body
            );

            // Send email
            if (sendEmail($mailConfig)) {
                return redirect()->route('admin.forgot.form')->with('success', 'We have e-mailed your password reset link.');
            } else {
                return redirect()->route('admin.forgot.form')->with('fail', 'Something went wrong');
            }
        }
    }

    public function resetPassword($token)
    {
        $passwordResetPassword = new PasswordResetToken();

        $check_token = $passwordResetPassword->asObject()->where('token', $token)->first();

        if (!$check_token) {
            return redirect()->route('admin.forgot.form')->with('fail', 'Invalid token. Request another reset password link.');
        } else {
            //Check if token not expired (Not older than 15 minutes)
            $diffMins = Carbon::now()->diffInMinutes(
                Carbon::createFromFormat('Y-m-d H:i:s', $check_token->created_at)
            );

            if ($diffMins > 15) {
                //If token expired (older than 15 minutes)
                return redirect()->route('admin.forgot.form')->with('fail', 'Token expired. Request another reset password link.');
            } else {
                return view('backend/pages/auth/reset', [
                    'pageTitle' => 'Reset password',
                    'validation' => null,
                    'token' => $token
                ]);
            }
        }
    }

    public function resetPasswordHandler($token)
    {
        $isValid = $this->validate([
            'new_password' => [
                'rules' => 'required|min_length[5]|max_length[20]|is_password_strong[new_password]',
                'errors' => [
                    'required' => 'Enter new password',
                    'min_length' => 'New password must have atleast minimum of 5 characters.',
                    'max_length' => 'New password must have maximum of 20 characters.',
                    'is_password_strong' => 'New password must contains atleast 1 uppercase, 1 lowercase, 1 number and 1 special character.',
                ],
            ],
            'confirm_new_password' => [
                'rules' => 'required|matches[new_password]',
                'errors' => [
                    'required' => 'Confirm new password',
                    'matches' => 'Passwords not matches.',
                ]
            ],
        ]);
        if (!$isValid) {
            return view('backend/pages/auth/reset', [
                'pageTitle' => 'Reset password',
                'validation' => null,
                'token' => $token,
            ]);
        } else {
            //Get token details
            $passwordResetPassword = new PasswordResetToken();
            $get_token = $passwordResetPassword->asObject()->where('token', $token)->first();

            //Get user (admin) details
            $user = new User();
            $user_info = $user->asObject()->where('email', $get_token->email)->first();

            if (!$get_token) {
                return redirect()->back()->with('fail', 'Invalid token!')->withInput();
            } else {
                //Update admin password in DB
                $user->where('email', $user_info->email)
                    ->set(['password' => Hash::make($this->request->getVar('new_password'))])
                    ->update();

                //Send notification to user (admin) email address
                $mail_data = array(
                    'user' => $user_info,
                    'new_password' => $this->request->getVar('new_password')
                );

                $view = \Config\Services::renderer();
                $mail_body = $view->setVar('mail_data', $mail_data)->render('email-templates/password-changed-email-template');

                $mailConfig = array(
                    'mail_from_email' => env('EMAIL_FROM_ADDRESS'),
                    'mail_from_name' => env('EMAIL_FROM_NAME'),
                    'mail_recipient_email' => $user_info->email,
                    'mail_recipient_name' => $user_info->name,
                    'mail_subject' => 'Password Changed',
                    'mail_body' => $mail_body
                );

                if (sendEmail($mailConfig)) {
                    //Delete token
                    $passwordResetPassword->where('email', $user_info->email)->delete();

                    //Redirect and display message on login page
                    return redirect()->route('admin.login.form')->with('success', 'Done!, Your password has been changed. Use new password to login into system.');
                } else {
                    return redirect()->back()->with('fail', 'Something went wrong')->withInput();
                }
            }
        }
    }
}
