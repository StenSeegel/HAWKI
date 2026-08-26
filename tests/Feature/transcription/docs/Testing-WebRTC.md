Testing WebRTC applications
When writing automated tests for your WebRTC applications, there are useful configurations that can be enabled for browsers that make development and testing easier.

Chrome
When running automated tests on Chrome, the following arguments are useful when launching:

--allow-file-access-from-files - Allows API access for file:// URLs
--disable-translate - Disables the translation popup
--use-fake-ui-for-media-stream - Provide fake media streams. Useful when running on CI servers.
--use-file-for-fake-audio-capture=<filename> - Provide a file to use when capturing audio.
--use-file-for-fake-video-capture=<filename> - Provide a file to use when capturing video.
--headless - Run in headless mode. Useful when running on CI servers.
--mute-audio - Mute audio output.
