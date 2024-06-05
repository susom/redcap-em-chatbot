# Use an official Node runtime as a parent image
FROM node:14-alpine

# Set the working directory to /app
WORKDIR /app

# copy the package.json to cache the required npm libraries
COPY package*.json ./

# Install any needed packages
RUN npm install

# INSTALL VIM
RUN apk add --no-cache vim

# Copy aliases
COPY .bash_aliases /root/
RUN echo "if [ -f /root/.bash_aliases ]; then . /root/.bash_aliases; fi" >> /root/.bashrc

# Copy the current directory contents into the container at /app
COPY . .

# Build the app
RUN npm run build

# Install serve globally
RUN npm install -g serve

# Set a default environment variable for the port
ENV PORT=8080

# Expose the port specified by the environment variable
EXPOSE $PORT

# Set the command to start the app
CMD ["npm", "start"]
# CMD ["serve", "-s", "build"]


