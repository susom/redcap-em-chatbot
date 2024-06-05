// THIS FILE NEEDS TO BE COPIED INTO /build/static/js/ after an "npm run build" to use the static generated files 
// to include into external site DOM
(function () {
    function loadScript(url, callback) {
      console.log('loadScript called with url:', url);
      const script = document.createElement('script');
      script.type = 'text/javascript';
      script.onload = function () {
        callback();
      };
      script.src = url;
      document.getElementsByTagName('head')[0].appendChild(script);
      console.log('Script appended to the head:', script);
    }
  
    function loadCSS(url) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.type = 'text/css';
      link.href = url;
      document.getElementsByTagName('head')[0].appendChild(link);
    }
  
    // WHEREVER these files are hosted (mabye with the EM itself or on GCP) , once built get the proper things to change   
    var staticPath    = '<public_host_addy>/static';
    var js_hash       = '<static_hash>';
    var css_hash      = '<static_hash>';
    
    var main_js  = '/js/main.' + js_hash + '.js';
    var main_css = '/css/main.'+ css_hash +'.css';
  
    // js_chunk = "/js/combined.js";
  
    // Load the React app's main JavaScript file
    loadScript(staticPath + main_js, function () {
      // Load the React app's CSS file
      loadCSS(staticPath + main_css);
  
      //ACTAUALLY NONE OF THE BELOW IS NECESSARY, THE APP EXPORTS AND TRYS TO APPEND TO "id='root'"

      // the react ui loads even if i dont actually append it to anything  what the heck now?
      // Render the React app inside the custom div
      var appDiv = document.getElementById('REDCap_Chatbot');
      
      // console.log("appDiv",appDiv);
      // console.log("React",window.React);
      // console.log("ReactDOM",window.ReactDOM);
      // console.log("REDCap_Chatbot",REDCap_Chatbot);
      // console.log("whats false", appDiv && window.React && window.ReactDOM && window.REDCap_Chatbot)
  
      if (appDiv && window.React && window.ReactDOM && window.REDCap_Chatbot) {
        window.ReactDOM.render(window.React.createElement(REDCap_Chatbot), appDiv);
      }else{
        console.log('Error: Missing required elements or components.');
      }
    });
  })();
  