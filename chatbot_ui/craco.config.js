// craco.config.js
// when "npm run build" ,  webpack wraps entire app to protect scope
// but to include app to external website (ie REDCcap) will need to change that default behaviour
// craco allows us to make modifications to webpack config  without actually "eject"ing the app (ie make visible and editble all the configuration files but increases headache) without
module.exports = {
  webpack: {
    configure: (webpackConfig, { env, paths }) => {
      // Expose the main application as a global variable (e.g., window.MyReactApp)
      webpackConfig.output.library = "REDCap_Chatbot";
      webpackConfig.output.libraryTarget = "window";

      return webpackConfig;
    },
  },
};

