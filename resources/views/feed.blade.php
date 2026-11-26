{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $title }}</title>
        <link>{{ $link }}</link>
        <description>Novels, short stories, poems, essays and notes by Georgina Awani.</description>
        <language>en</language>
        <atom:link href="{{ $self }}" rel="self" type="application/rss+xml" />
@foreach ($entries as $entry)
        <item>
            <title>{{ $entry['title'] }}</title>
            <link>{{ $entry['link'] }}</link>
            <guid isPermaLink="true">{{ $entry['link'] }}</guid>
            <pubDate>{{ $entry['date']->toRssString() }}</pubDate>
            <description>{{ $entry['text'] }}</description>
        </item>
@endforeach
    </channel>
</rss>
