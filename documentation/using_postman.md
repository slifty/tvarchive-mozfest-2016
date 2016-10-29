# Postman

Postman is a Google Chrome tool that makes it possible to make requests to 
an API without using code. This is a great way to use the Duplitron to search TV
without needing to be a programmer.



## Setting Up Postman

1. To install Postman, follow [these instructions](https://www.getpostman.com/docs/introduction).

2. Once installed, import the pre-created call collection from `data/Duplitron_MozFest.postman_collection`

	> From [the documentation](https://www.getpostman.com/docs/collections): You can import a collection file. Click on the ‘Import’ button on the top bar, and paste a URL to the collection, or the collection JSON itself, and click ‘Import’.

3. Now you have a list of pre-created actions you can take in your postman.


## The API Calls

The pre-made collection has been built to point to a Dt5k project which is already
populated with a bunch of Cable news programs from October 20th to October 28th.
This means you can run audio searches against this window of time.

- You can view a list of the media included in this project by running the "View All
Media" API call.
- You can view an example piece of media by running the "View Media" API call.
- You can view an example match task by running the "View Match Task" API call.

You can also run custom match tasks against any sounds you want to search for.

Note: Searches take a few minutes to run... especially when there are lots of hits.

To do this you need to:

1. Create a new media file using the "Create Media" API call. Replace media_path with
a public link to the MP3 that you want to compare.  The object that is returned will
have an ID.  Take note of this ID.

2. Create a new match task using the "Create Match Task" API call.  Replace "match_id"
with the ID of the media you just created.