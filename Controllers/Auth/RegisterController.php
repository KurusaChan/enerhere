<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Registered;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Mail\SimpleHtmlMail;
use App\Models\Invite;
use App\Models\User;
use App\Models\RegistrationRequest;

class RegisterController extends Controller
{

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/profile';

    /**
     * Show the application registration form.
     *
     * @return \Illuminate\Http\Response
     */
    public function showRegistrationForm($code = null, Request $request)
    {
        $invite = Invite::notUsed()->where('code', $code)->first();
        if($invite){
            return view('auth.register', [
                'invite' => $invite
            ]);
        }
        return view('auth.no_invite_form', [
            'has_invalid_code' => !empty($code)
        ]);
    }
    
    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function register($code, Request $request)
    {
        $invite = Invite::notUsed()->where('code', $code)->first();
        if(!$invite){
            return redirect()->route('register')->with('global-error', 'Приглашение не действительно');
        }
        
        $this->validator($request->all())->validate();

        $invite->used = 1;
        $invite->save();
        
        $user = $this->create($request->all(), $invite);
        event(new Registered($user));

        $this->guard()->login($user);

        return $this->registered($request, $user)
                        ?: redirect($this->redirectPath());
    }
    
    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => ['required', 'max:150'],
            'lastname' => ['required', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users'],
            'password' => ['required', 'min:6', 'confirmed'],
        ], [
            'name.required' => 'Пожалуйста, укажите имя',
            'name.max' => 'Имя слишком длинное',
            'lastname.required' => 'Пожалуйста, укажите фамилию',
            'lastname.max' => 'Фамилия слишком длинная',
            'lastname.max' => 'Фамилия слишком длинная',
            'email.required' => 'Пожалуйста, укажите эл. адрес',
            'email.email' => 'Эл. адрес недействителен',
            'email.max' => 'Эл. адрес слишком длинный',
            'email.unique' => 'Пользователь с указанным эл. адресом уже зарегистрирован в системе',
            'password.required' => 'Пожалуйста, укажите пароль',
            'password.min' => 'Пароль слишком короткий',
            'password.confirmed' => 'Пароли не совпадают'
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @param  \App\Models\Invite  $invite
     * @return \App\User
     */
    protected function create(array $data, Invite $invite)
    {
        return User::create([
            'invite_id' => $invite->id,
            'name' => $data['name'],
            'lastname' => $data['lastname'],
            'email' => $data['email'],
            'inn' => $data['inn'] ?? null,
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
        ]);
    }
    
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function registerRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255']
        ]);
        $validator->validate();
        
        $mail = '
            Email: '.$request->input('email').' <br>
            Имя: '.$request->input('name').' <br>
            Телефон: '.$request->input('phone').' <br>
            Есть эл. автомобиль: '.($request->input('has_car') ? 'да' : 'нет').' <br>
            Готовы поделиться зарядкой?: '.($request->input('share') ? 'да' : 'нет').' <br>
            Мощность зарядки: '.$request->input('power').'<br>
            Адрес: '.$request->input('address').'
            <br><br>
            IP: '.$request->server('REMOTE_ADDR').'<br>
            Реферер: '.($request->cookie('u_ref') ?? $request->header('referer')).'
        ';
        
        RegistrationRequest::create([
            'confirmed' => 0,
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'has_el_car' => $request->input('has_car') ? 1 : 0,
            'share' => $request->input('share') ? 1 : 0,
            'power' => $request->input('power'),
            'address' => $request->input('address')
        ]);
        
        $this->notifyAdmin('Заявка на регистрацию', $mail);
        
        return redirect()->route('register')->with('request-sent', true);
    }
}
