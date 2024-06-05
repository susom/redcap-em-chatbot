
export function formatTimestamp(timestamp) {
  const date = new Date(timestamp);
  const year = date.getFullYear().toString().slice(-2);
  const month = `0${date.getMonth() + 1}`.slice(-2);
  const day = `0${date.getDate()}`.slice(-2);
  const hours = `0${date.getHours()}`.slice(-2);
  const minutes = `0${date.getMinutes()}`.slice(-2);
  return `${month}/${day}/${year} ${hours}:${minutes}`;
}

export function truncateString(str, limit) {
    
  if (str.length <= limit) {
    return str;
  }
  const subString = str.substring(0, limit - 1);
  return `${subString.substring(0, subString.lastIndexOf(' '))}...`;
}
