# wpAuth by Orcnd

## what it does

this generates a secure login link for users
it also creates user if it doesn't exist yet

## but why ?

welp i needed a cross site login for wordpress. this plugin allows my other platform can login wordpress without register and login again

## but how ?

first of all i wanted to do this in secure as possible

### so it has stages

1. create a control key
   we need this key to create access key.
   we need this because we want to make this access key work only for short time of period
   so we create a control key has with time, email and master key

2. ask for access key with control key
   now we have control key we are able to ask for access key. plugin will provide an access key which will be only accessible for short time

3. now we able to request link for login with access key
   plugin will return an unique id which will expire in short time

4. redirect to user wordpress usr with unique id
   that page will make user login to wordpress

5. auto login page of wordpress will redirect user to page that specified in settings

## here is a sequence diagram about it

![it is my first sequence diagram be nice!](how.png 'how')

## how to use it

### wordpress side

- create a wordpress page and add shortcode [wpAuth]
- set settings on wordpress settings general section
  you need to set key, full redirect url, username prefix and some salt

### api request side

## parameters that you will need

- key: you need to store it wordpress plugin settings and your requester server
- email : login user email
- time : in ISO 8601 date format

you need a control key generator function first on your requester server
`md5('key'.'email'.'time'.'control_key')`

than make a rest request to your wordpress site
method is `post`
request parameters are `email`,`time` and `control_token` that you generated above
endpoint for this request is `/wp-json/wpauth/v1/token`
you will get an access token with this request as `access_token`

now we can request a link id with our access token
in order to do that you need to make a request
method is `post`
request parameter will be `email`,`name` and `access_token`
endpoint for this request is `/wp-json/wpauth/v1/generateLogin`

this request will return an unique id as `login`

now redirect the user to wordpress page that you created and added `[wpAuth]` shortcode before with get parameter `?token=login`
that login provided with last request
