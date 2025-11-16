/**
 * WebSocket 管理服務
 * 
 * 統一管理 WebSocket 連線、訂閱和事件處理
 */

class WebSocketService {
  constructor() {
    this.echo = null;
    this.channels = new Map();
    this.listeners = new Map();
    this.connected = false;
  }

  /**
   * 初始化 WebSocket 連線
   */
  init() {
    if (window.Echo) {
      this.echo = window.Echo;
      this.setupConnectionHandlers();
      this.connected = true;
      console.log('✅ WebSocket 服務已初始化');
    } else {
      console.error('❌ Laravel Echo 未載入');
    }
  }

  /**
   * 設定連線處理器
   */
  setupConnectionHandlers() {
    // Pusher 連線事件
    if (window.Pusher) {
      window.Pusher.logToConsole = import.meta.env.DEV;
      
      const pusher = this.echo.connector.pusher;
      
      pusher.connection.bind('connected', () => {
        console.log('🔗 WebSocket 已連線');
        this.connected = true;
      });

      pusher.connection.bind('disconnected', () => {
        console.log('🔌 WebSocket 已斷線');
        this.connected = false;
      });

      pusher.connection.bind('error', (error) => {
        console.error('❌ WebSocket 連線錯誤:', error);
      });
    }
  }

  /**
   * 訂閱股票價格頻道
   */
  subscribeStockPrices(callback) {
    const channelName = 'stock-prices';
    
    if (!this.channels.has(channelName)) {
      const channel = this.echo.channel(channelName);
      this.channels.set(channelName, channel);
    }

    const channel = this.channels.get(channelName);
    channel.listen('.stock.price.updated', (data) => {
      console.log('📊 收到股票價格更新:', data);
      callback(data);
    });

    return () => this.unsubscribe(channelName);
  }

  /**
   * 訂閱特定股票的價格頻道
   */
  subscribeStockPrice(symbol, callback) {
    const channelName = `stock-prices.${symbol}`;
    
    if (!this.channels.has(channelName)) {
      const channel = this.echo.channel(channelName);
      this.channels.set(channelName, channel);
    }

    const channel = this.channels.get(channelName);
    channel.listen('.stock.price.updated', (data) => {
      console.log(`📊 收到 ${symbol} 價格更新:`, data);
      callback(data);
    });

    return () => this.unsubscribe(channelName);
  }

  /**
   * 訂閱選擇權價格頻道
   */
  subscribeOptionPrices(callback) {
    const channelName = 'option-prices';
    
    if (!this.channels.has(channelName)) {
      const channel = this.echo.channel(channelName);
      this.channels.set(channelName, channel);
    }

    const channel = this.channels.get(channelName);
    channel.listen('.option.price.updated', (data) => {
      console.log('📈 收到選擇權價格更新:', data);
      callback(data);
    });

    return () => this.unsubscribe(channelName);
  }

  /**
   * 訂閱特定標的選擇權價格頻道
   */
  subscribeOptionPrice(underlying, callback) {
    const channelName = `option-prices.${underlying}`;
    
    if (!this.channels.has(channelName)) {
      const channel = this.echo.channel(channelName);
      this.channels.set(channelName, channel);
    }

    const channel = this.channels.get(channelName);
    channel.listen('.option.price.updated', (data) => {
      console.log(`📈 收到 ${underlying} 選擇權價格更新:`, data);
      callback(data);
    });

    return () => this.unsubscribe(channelName);
  }

  /**
   * 訂閱市場警報頻道
   */
  subscribeMarketAlerts(callback) {
    const channelName = 'market-alerts';
    
    if (!this.channels.has(channelName)) {
      const channel = this.echo.channel(channelName);
      this.channels.set(channelName, channel);
    }

    const channel = this.channels.get(channelName);
    channel.listen('.market.alert', (data) => {
      console.log('🚨 收到市場警報:', data);
      callback(data);
    });

    return () => this.unsubscribe(channelName);
  }

  /**
   * 取消訂閱頻道
   */
  unsubscribe(channelName) {
    if (this.channels.has(channelName)) {
      this.echo.leave(channelName);
      this.channels.delete(channelName);
      console.log(`📴 已取消訂閱頻道: ${channelName}`);
    }
  }

  /**
   * 取消所有訂閱
   */
  unsubscribeAll() {
    this.channels.forEach((channel, channelName) => {
      this.echo.leave(channelName);
    });
    this.channels.clear();
    console.log('📴 已取消所有訂閱');
  }

  /**
   * 檢查連線狀態
   */
  isConnected() {
    return this.connected;
  }

  /**
   * 取得當前訂閱的頻道列表
   */
  getSubscribedChannels() {
    return Array.from(this.channels.keys());
  }
}

// 建立單例實例
const webSocketService = new WebSocketService();

// 自動初始化
if (typeof window !== 'undefined') {
  window.addEventListener('DOMContentLoaded', () => {
    webSocketService.init();
  });
}

export default webSocketService;