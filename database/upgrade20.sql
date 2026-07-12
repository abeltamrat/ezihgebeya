-- Add search-demand events to the shared analytics pipeline.

ALTER TABLE events
    MODIFY event_type ENUM('view','favorite','inquiry','cart_add','order','ad_impression','ad_click','video_view','video_cta_click','web_vital','search') NOT NULL;

ALTER TABLE event_daily_summaries
    MODIFY event_type ENUM('view','favorite','inquiry','cart_add','order','ad_impression','ad_click','video_view','video_cta_click','web_vital','search') NOT NULL;
