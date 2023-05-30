# REDCapChatBot

An EM intended for system wide distribution to inject frontend Support Chat Bot code

### What is the chatbot?

The chatbot itself is a self-contained Python/REACT app hosted on GCP/GAE.  The git [found here](https://github.com/susom/REDCap_ChatGPT_Bot) has further description of the actual components.

### What is in this EM?

The frontend REACT portion can generate static build files which can be injected into an external websites DOM for better UI integration.
This EM will be enabled globally and include the those build files and inject them into REDCap's UI.  That UI will still communicate with the GCP/GAE hosted backend.



