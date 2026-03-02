const { contextBridge } = require('electron');

contextBridge.exposeInMainWorld('PrismDesktop', {
  platform: process.platform,
  version: '0.1.0'
});
